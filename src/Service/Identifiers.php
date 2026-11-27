<?php

declare(strict_types=1);

namespace MonitorHQ\Service;

use MonitorHQ\Exception\ValidationException;
use MonitorHQ\Model\IdentifierCheck;

final class Identifiers extends Service
{
    public function check(string $identifier): IdentifierCheck
    {
        try {
            return IdentifierCheck::fromArray($this->transport->request('POST', 'identifier/check', [], ['identifier' => $identifier]));
        } catch (ValidationException $e) {
            if (is_array($e->body) && ($e->body['valid'] ?? null) === false) {
                return IdentifierCheck::fromArray($e->body);
            }

            throw $e;
        }
    }
}
