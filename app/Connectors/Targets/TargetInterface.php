<?php

namespace App\Connectors\Targets;

use App\Connectors\Manifest;

/**
 * A data-plane target adapter.
 *
 * Contract (specification §9): receive a validated source, apply it atomically,
 * and report what changed as a structured result. Adapters are the **only**
 * place that names an RPC — a manifest supplies data, never the method.
 */
interface TargetInterface
{
    /** The manifest `target` value this adapter serves. */
    public function target(): string;

    /**
     * Apply one validated source.
     *
     * @param Manifest             $manifest The owning manifest (for namespacing).
     * @param array<string, mixed> $source   A source from Manifest::sources().
     *
     * @return array{source_id: string, target: string, ref: string, success: bool,
     *               status: string, message: string, steps: list<string>,
     *               warnings: list<string>, action?: string, state?: string|null}
     */
    public function apply(Manifest $manifest, array $source): array;
}
