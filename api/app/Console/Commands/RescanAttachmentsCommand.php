<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Message;
use App\Services\Mail\AttachmentRisk;
use Illuminate\Console\Command;

/**
 * Re-run the metadata screening over every stored attachment (after the rules
 * change, or for mail synced before screening existed). Downloads nothing.
 */
class RescanAttachmentsCommand extends Command
{
    protected $signature = 'sovera:rescan-attachments';

    protected $description = 'Re-screen all attachments by name/type and flag risky messages (no downloads)';

    public function handle(): int
    {
        $counts = [AttachmentRisk::DANGER => 0, AttachmentRisk::CAUTION => 0];
        Attachment::query()->orderBy('id')->chunkById(500, function ($attachments) use (&$counts): void {
            foreach ($attachments as $a) {
                // A content-sniff verdict (from an explicit download) is never downgraded.
                $verdict = str_starts_with((string) $a->risk_reason, 'The file content')
                    ? ['level' => AttachmentRisk::DANGER, 'reason' => $a->risk_reason]
                    : AttachmentRisk::screen($a->filename, $a->mime_type, (bool) $a->is_inline);
                $level = $verdict['level'] ?? null;
                $reason = $verdict['reason'] ?? null;
                if ($level !== $a->risk_level || $reason !== $a->risk_reason) {
                    $a->forceFill(['risk_level' => $level, 'risk_reason' => $reason])->save();
                }
                if ($level !== null) {
                    $counts[$level]++;
                }
            }
        });

        $danger = Attachment::query()->where('risk_level', AttachmentRisk::DANGER)->distinct()->pluck('message_id');
        $caution = Attachment::query()->where('risk_level', AttachmentRisk::CAUTION)->distinct()->pluck('message_id');
        Message::query()->whereIn('id', $danger)->where('is_risky', false)->update(['is_risky' => true]);
        Message::query()->whereNotIn('id', $danger)->where('is_risky', true)->update(['is_risky' => false, 'risk_acknowledged_at' => null]);
        Message::query()->whereIn('id', $caution)->where('has_caution', false)->update(['has_caution' => true]);
        Message::query()->whereNotIn('id', $caution)->where('has_caution', true)->update(['has_caution' => false]);

        $this->info("{$counts['danger']} dangerous attachment(s) in ".Message::query()->where('is_risky', true)->count().' message(s) (blocked); '
            ."{$counts['caution']} archive(s) in ".Message::query()->where('has_caution', true)->count().' message(s) (caution).');

        return self::SUCCESS;
    }
}
