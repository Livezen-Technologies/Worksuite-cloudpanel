<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Domain;
use Illuminate\Support\Collection;

/**
 * proxy:prune-certs — removes the superseded certificate rows proxy:sync-certs piled up while
 * the enterprise edges' two certificates kept replacing each other (hundreds of rows per domain).
 *
 * Only `replaced` and `expired` rows are candidates; active, issuing and failed rows are never
 * touched. A domain's history rows are grouped into renewals: rows whose expiry lies within a day
 * of the group's first are one renewal (every ingress's copy of it, seen many times). Each group
 * keeps its earliest row, which records when that certificate was first seen, and only the newest
 * $keep groups are kept. Running it again deletes nothing.
 */
class CertificateHistoryPruner
{
    /** @return array<int, array{domain: string, rows: int, keep: int, delete: int}> one entry per domain that has rows to delete */
    public function prune(bool $dryRun = true, int $keep = 10, ?string $domainName = null): array
    {
        $keep = max(1, $keep);
        $query = Certificate::allTenants()->whereIn('status', ['replaced', 'expired'])
            ->when($domainName, fn ($q) => $q->whereIn('domain_id', Domain::allTenants()->where('name', $domainName)->select('id')));
        $domainIds = (clone $query)->select('domain_id')->groupBy('domain_id')->havingRaw('count(*) > 1')->pluck('domain_id');
        $out = [];
        foreach ($domainIds as $domainId) {
            $history = Certificate::allTenants()->where('domain_id', $domainId)->whereIn('status', ['replaced', 'expired'])
                ->orderBy('created_at')->orderBy('id')->get(['id', 'domain_id', 'status', 'expires_at', 'created_at']);
            $keepIds = $this->survivors($history, $keep);
            $delete = $history->pluck('id')->diff($keepIds)->values();
            if ($delete->isEmpty()) {
                continue;
            }
            $total = Certificate::allTenants()->where('domain_id', $domainId)->count();
            $out[] = [
                'domain' => (string) (Domain::allTenants()->find($domainId)?->name ?? $domainId),
                'rows' => $total, 'keep' => $total - $delete->count(), 'delete' => $delete->count(),
            ];
            if (! $dryRun) {
                foreach ($delete->chunk(500) as $ids) {
                    Certificate::allTenants()->whereIn('id', $ids->all())->delete();
                }
            }
        }

        return $out;
    }

    /** The ids to keep: the earliest row of each renewal group, newest $keep groups. */
    private function survivors(Collection $history, int $keep): array
    {
        $groups = [];
        foreach ($history->sortBy(fn ($c) => $c->expires_at?->getTimestamp() ?? 0) as $c) {
            $last = array_key_last($groups);
            $anchor = $last !== null ? $groups[$last]['anchor'] : null;
            if ($last === null || ! $c->expires_at || ! $anchor || $c->expires_at->getTimestamp() - $anchor->getTimestamp() > 86400) {
                $groups[] = ['anchor' => $c->expires_at, 'rows' => [$c]];
            } else {
                $groups[$last]['rows'][] = $c;
            }
        }
        // Newest renewals last (sorted by expiry); keep the earliest-seen row of each.
        return collect(array_slice($groups, -$keep))
            ->map(fn ($g) => collect($g['rows'])->sortBy(fn ($c) => [$c->created_at?->getTimestamp() ?? 0, $c->id])->first()->id)
            ->all();
    }
}
