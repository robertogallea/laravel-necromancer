<?php

declare(strict_types=1);

namespace LaravelNecromancer\Tests\Fixtures\Mail;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class NecromancerWelcomeMail extends Mailable implements ShouldQueue
{
    public string $queue = 'notifications';

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome!');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome');
    }
}
