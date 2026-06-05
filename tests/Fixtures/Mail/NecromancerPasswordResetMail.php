<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NecromancerPasswordResetMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset');
    }
}
