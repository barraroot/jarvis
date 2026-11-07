<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * wuzapi failed while provisioning, pairing or removing a session (RF-04):
 * the API maps it to 502 with the pt-BR message. `upstreamDetail` is the
 * already redacted reason from `WuzapiRequestException`; never secrets.
 */
class WhatsappUpstreamUnavailableException extends RuntimeException
{
    public function __construct(public readonly ?string $upstreamDetail = null)
    {
        parent::__construct(__('whatsapp.session.upstream_unavailable'));
    }
}
