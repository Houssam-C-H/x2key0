<?php

declare(strict_types=1);

namespace App\Domains\Reporting\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Weekly summary for admins and finance (docs/ADMIN_PLAN.md item 18): headline figures in the body, the full report
 * as a CSV attachment. Staff e-mail, in English like the admin.
 */
final class WeeklySummaryMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, string>  $figures  label => formatted value
     * @param  array<string, int>  $queues
     */
    public function __construct(
        public readonly string $period,
        public readonly string $range,
        public readonly array $figures,
        public readonly array $queues,
        public readonly string $csv,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config()->string('x2key.brand.name', 'X2KEY')." weekly report {$this->period}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-summary', with: [
            'period' => $this->period,
            'range' => $this->range,
            'figures' => $this->figures,
            'queues' => $this->queues,
            'url' => rtrim(config()->string('app.url'), '/').'/admin/reports',
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromData(fn (): string => $this->csv, "report-{$this->period}.csv")->withMime('text/csv')];
    }
}
