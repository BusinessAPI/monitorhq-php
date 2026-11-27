<?php

declare(strict_types=1);

namespace MonitorHQ\Model;

use MonitorHQ\Internal\Arr;

final class Account
{
    /**
     * @param array<string, mixed>|null $currentTeam
     * @param list<array<string, mixed>> $ownedTeams
     * @param list<array<string, mixed>> $memberOfTeams
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $accountType,
        public readonly ?array $currentTeam,
        public readonly array $ownedTeams,
        public readonly array $memberOfTeams,
        public readonly array $raw,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::string($data, 'name'),
            Arr::string($data, 'email'),
            Arr::string($data, 'account_type'),
            Arr::map($data, 'current_team'),
            Arr::rows($data, 'owned_teams') ?? [],
            Arr::rows($data, 'member_of_teams') ?? [],
            $data,
        );
    }
}
