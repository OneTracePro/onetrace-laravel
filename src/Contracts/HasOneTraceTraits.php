<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Contracts;

/**
 * A user model that decides which traits identify() sends: email, phone, first_name, plan…
 * Traits email, phone, telegram_chat_id and viber_id identify the profile; null deletes a trait.
 */
interface HasOneTraceTraits
{
    /**
     * @return array<string, mixed>
     */
    public function oneTraceTraits(): array;
}
