<?php

namespace Tests\Unit\Support;

use App\Support\WhatsappHmac;
use Tests\TestCase;

class WhatsappHmacTest extends TestCase
{
    public function test_a_correctly_signed_body_within_tolerance_verifies(): void
    {
        $hmac = new WhatsappHmac('shared-secret');
        $timestamp = time();
        $signature = $hmac->sign('{"foo":"bar"}', $timestamp);

        $this->assertTrue($hmac->verify('{"foo":"bar"}', $signature, $timestamp));
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        $hmac = new WhatsappHmac('shared-secret');
        $timestamp = time();
        $signature = $hmac->sign('{"foo":"bar"}', $timestamp);

        $this->assertFalse($hmac->verify('{"foo":"baz"}', $signature, $timestamp));
    }

    public function test_a_signature_from_a_different_secret_fails_verification(): void
    {
        $hmac = new WhatsappHmac('shared-secret');
        $timestamp = time();
        $signature = (new WhatsappHmac('different-secret'))->sign('{"foo":"bar"}', $timestamp);

        $this->assertFalse($hmac->verify('{"foo":"bar"}', $signature, $timestamp));
    }

    public function test_a_signature_older_than_the_tolerance_window_is_rejected(): void
    {
        $hmac = new WhatsappHmac('shared-secret');
        $timestamp = time() - 301;
        $signature = $hmac->sign('{"foo":"bar"}', $timestamp);

        $this->assertFalse($hmac->verify('{"foo":"bar"}', $signature, $timestamp));
    }

    public function test_a_signature_from_the_future_beyond_the_tolerance_window_is_rejected(): void
    {
        $hmac = new WhatsappHmac('shared-secret');
        $timestamp = time() + 301;
        $signature = $hmac->sign('{"foo":"bar"}', $timestamp);

        $this->assertFalse($hmac->verify('{"foo":"bar"}', $signature, $timestamp));
    }

    public function test_a_signature_within_the_tolerance_window_boundary_still_verifies(): void
    {
        $hmac = new WhatsappHmac('shared-secret');
        $timestamp = time() - 299;
        $signature = $hmac->sign('{"foo":"bar"}', $timestamp);

        $this->assertTrue($hmac->verify('{"foo":"bar"}', $signature, $timestamp));
    }
}
