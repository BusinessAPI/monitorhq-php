<?php

declare(strict_types=1);

namespace MonitorHQ\Service;

use MonitorHQ\Model\Account as AccountModel;

final class Account extends Service
{
    public function get(): AccountModel
    {
        return AccountModel::fromArray($this->unwrap($this->transport->request('GET', 'account')));
    }
}
