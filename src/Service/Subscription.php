<?php

declare(strict_types=1);

namespace MonitorHQ\Service;

use MonitorHQ\Model\Subscription as SubscriptionModel;

final class Subscription extends Service
{
    public function get(): SubscriptionModel
    {
        return SubscriptionModel::fromArray($this->unwrap($this->transport->request('GET', 'subscription')));
    }
}
