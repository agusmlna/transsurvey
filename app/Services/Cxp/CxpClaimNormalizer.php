<?php

namespace App\Services\Cxp;

use App\Exceptions\CxpSsoException;

/**
 * Meratakan bentuk klaim TransCXP ke satu bentuk internal.
 *
 * Dipakai oleh verifier JWT dan endpoint launch session supaya keduanya
 * menerima bentuk payload yang sama.
 */
class CxpClaimNormalizer
{
    public function normalize(array $payload): array
    {
        if (array_key_exists('ticket', $payload) && ! is_array($payload['ticket'])) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => 'ticket']);
        }

        $ticket = $payload['ticket'] ?? null;

        if (is_array($ticket) && array_is_list($ticket) && $ticket !== []) {
            if (count($ticket) > 1) {
                throw CxpSsoException::invalid(
                    'ambiguous_ticket',
                    __('Token SSO memuat lebih dari satu tiket. Buka CSAT dari satu tiket.')
                );
            }

            if (! is_array($ticket[0])) {
                throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => 'ticket']);
            }

            $payload['ticket'] = $ticket[0];
            $ticket = $payload['ticket'];
        }

        if (is_array($ticket) && is_array($ticket['customer'] ?? null) && ! is_array($payload['customer'] ?? null)) {
            $payload['customer'] = $ticket['customer'];
        }

        if (array_key_exists('customer', $payload) && ! is_array($payload['customer'])) {
            throw CxpSsoException::invalid('invalid_claim', __('Data pada token SSO tidak valid.'), ['claim' => 'customer']);
        }

        $email = $payload['email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            return $payload;
        }

        $email = trim($email);

        if (! is_array($payload['user'] ?? null)) {
            $payload['user'] = ['email' => $email];
        } elseif (! isset($payload['user']['email'])) {
            $payload['user']['email'] = $email;
        }

        return $payload;
    }
}
