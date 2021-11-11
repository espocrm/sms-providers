<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Modules\SmsProviders\Twilio;

use Espo\Core\Sms\Sender;
use Espo\Core\Sms\Sms;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Json;
use Espo\Core\Exceptions\Error;

use Espo\ORM\EntityManager;

use Espo\Entities\Integration;

use Throwable;

class TwilioSender implements Sender
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01';

    private const TIMEOUT = 10;

    private $config;

    private $entityManager;

    private $log;

    public function __construct(Config $config, EntityManager $entityManager, Log $log)
    {
        $this->config = $config;
        $this->entityManager = $entityManager;
        $this->log = $log;
    }

    public function send(Sms $sms): void
    {
        $toNumberList = $sms->getToNumberList();

        if (!count($toNumberList)) {
            throw new Error("No recipient phone number.");
        }

        foreach ($toNumberList as $number) {
            $this->sendToNumber($sms, $number);
        }
    }

    private function sendToNumber(Sms $sms, string $toNumber): void
    {
        $integration = $this->getIntegrationEntity();

        $sid = $integration->get('twilioAccountSid');
        $authToken = $integration->get('twilioAuthToken');
        $baseUrl = rtrim(
            $integration->get('apiBaseUrl') ??
            $this->config->get('twilioApiBaseUrl') ??
            self::BASE_URL
        );
        $timeout = $this->config->get('twilioSmsSendTimeout') ?? self::TIMEOUT;

        $fromNumber = $sms->getFromNumber();

        if (!$sid) {
            throw new Error("No Twilio Account SID.");
        }

        if (!$authToken) {
            throw new Error("No Twilio Auth Token.");
        }

        if (!$fromNumber) {
            throw new Error("No sender phone number.");
        }

        if (!$toNumber) {
            throw new Error("No recipient phone number.");
        }

        $url = $baseUrl . '/Accounts/' . $sid . '/Messages.json';

        $data = [
            'Body' => $sms->getBody(),
            'To' => self::formatNumber($toNumber),
        ];

        if ($fromNumber) {
            $data['From'] = self::formatNumber($fromNumber);
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($sid . ':' . $authToken),
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $ch = curl_init();

        curl_setopt($ch, \CURLOPT_URL, $url);
        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_HEADER, true);
        curl_setopt($ch, \CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, \CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $this->buildQuery($data));

        $response = curl_exec($ch);

        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        $headerSize = curl_getinfo($ch, \CURLINFO_HEADER_SIZE);

        $body = mb_substr($response, $headerSize);

        if ($code && !($code >= 200 && $code < 300)) {
            $this->processError($code, $body);

        }

        if ($error) {
            if (in_array($error, [\CURLE_OPERATION_TIMEDOUT, \CURLE_OPERATION_TIMEOUTED])) {
                throw new Error("Twilio SMS sending timeout.");
            }
        }
    }

    private function buildQuery(array $data): string
    {
        $itemList = [];

        foreach ($data as $key => $value) {
            $itemList[] = urlencode($key) . '=' . urlencode($value);
        }

        return implode('&', $itemList);
    }

    private static function formatNumber(string $number): string
    {
        return '+' . preg_replace('/[^0-9]/', '', $number);
    }

    private function processError(int $code, string $body): void
    {
        try {
            $data = Json::decode($body);

            $message = $data->message ?? null;
        }
        catch (Throwable $e) {
            $message = null;
        }

        if ($message) {
            $this->log->error("Twilio SMS sending error. Message: " . $message);
        }

        throw new Error("Twilio SMS sending error. Code: {$code}.");
    }

    private function getIntegrationEntity(): Integration
    {
        $entity = $this->entityManager
            ->getEntity(Integration::ENTITY_TYPE, 'Twilio');

        if (!$entity || !$entity->get('enabled')) {
            throw new Error("Twilio integration is not enabled");
        }

        return $entity;
    }
}
