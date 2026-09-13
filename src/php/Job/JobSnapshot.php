<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * A job offer as JSON: the evidence a stored verdict was formed from, so a later drain can re-score
 * the row from what it saw rather than from today's parse of a message that may be gone.
 *
 * Every `JobListing` constructor parameter is encoded, and `JobSnapshotTest` enforces that by
 * reflection. A snapshot that silently lost tomorrow's field would re-judge on less evidence than
 * the original, which is how an unknown turns into a zero.
 */
final class JobSnapshot
{
    public static function encode(JobListing $listing): string
    {
        return json_encode([
            'sourceName' => $listing->sourceName,
            'externalId' => $listing->externalId,
            'title' => $listing->title,
            'company' => $listing->company,
            'location' => $listing->location,
            'description' => $listing->description,
            'fields' => (object) $listing->fields,
            'url' => $listing->url,
            'contracts' => $listing->contracts,
            'workMode' => $listing->workMode,
            'salaryMinEur' => $listing->salaryMinEur,
            'salaryMaxEur' => $listing->salaryMaxEur,
            'tjmMinEur' => $listing->tjmMinEur,
            'tjmMaxEur' => $listing->tjmMaxEur,
            'payText' => $listing->payText,
            'publishedAt' => $listing->publishedAt,
            'observedAt' => $listing->observedAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $json): JobListing
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('instantané d\'offre illisible : ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || !isset($data['sourceName'], $data['externalId'])
            || !is_array($data['fields'] ?? null) || !is_array($data['contracts'] ?? null)) {
            throw new \InvalidArgumentException('instantané d\'offre sans la forme attendue');
        }

        $str = static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null;
        $int = static fn (mixed $v): ?int => is_int($v) || (is_float($v) && floor($v) === $v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;

        return new JobListing(
            sourceName: (string) $data['sourceName'],
            externalId: (string) $data['externalId'],
            title: (string) ($data['title'] ?? ''),
            company: (string) ($data['company'] ?? ''),
            location: (string) ($data['location'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            fields: $data['fields'],
            url: $str($data['url'] ?? null),
            contracts: array_values(array_map('strval', array_filter($data['contracts'], 'is_scalar'))),
            workMode: $str($data['workMode'] ?? null),
            salaryMinEur: $int($data['salaryMinEur'] ?? null),
            salaryMaxEur: $int($data['salaryMaxEur'] ?? null),
            tjmMinEur: $int($data['tjmMinEur'] ?? null),
            tjmMaxEur: $int($data['tjmMaxEur'] ?? null),
            payText: $str($data['payText'] ?? null),
            publishedAt: $str($data['publishedAt'] ?? null),
            observedAt: $str($data['observedAt'] ?? null),
        );
    }
}
