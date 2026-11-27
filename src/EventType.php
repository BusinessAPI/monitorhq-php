<?php

declare(strict_types=1);

namespace MonitorHQ;

enum EventType: string
{
    case CompanyRegistration = 'company_registration';

    case AbnRegistration = 'abn_registration';

    case GstRegistration = 'gst_registration';

    case DocumentLodged = 'document_lodged';

    case NameChange = 'name_change';

    case FormerNamePeriod = 'former_name_period';

    case BusinessNameAdded = 'business_name_added';

    case BusinessNameRemoved = 'business_name_removed';

    case StatusChange = 'status_change';

    case TypeChange = 'type_change';

    case AddressChange = 'address_change';

    case DateChange = 'date_change';

    case ReviewDateChange = 'review_date_change';

    case OtherChange = 'other_change';

    case PpsrRegistration = 'ppsr_registration';

    case InsolvencyNotice = 'insolvency_notice';

    case TradeMarkFiled = 'trade_mark_filed';

    case TradeMarkStatusChange = 'trade_mark_status_change';

    case TradeMarkOwnerChange = 'trade_mark_owner_change';

    case TradeMarkRenewalDue = 'trade_mark_renewal_due';

    case TradeMarkRenewed = 'trade_mark_renewed';

    case RegisterAdded = 'register_added';

    case RegisterStatusChange = 'register_status_change';

    case RegisterRemoved = 'register_removed';

    case RegisterConditionsChange = 'register_conditions_change';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::OtherChange : (self::tryFrom($value) ?? self::OtherChange);
    }
}
