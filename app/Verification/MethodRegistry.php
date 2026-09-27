<?php

declare(strict_types=1);

namespace App\Verification;

use App\Models\Project;

/**
 * Méthodes de vérification disponibles. Une méthode est proposée si elle est implémentée,
 * disponible dans le mode de la session et activée pour le projet ; la méthode simulée est
 * toujours proposée en sandbox (et jamais en production).
 */
final class MethodRegistry
{
    /** @var array<string, VerificationMethodInterface> */
    private array $methods = [];

    public function __construct(VerificationMethodInterface ...$methods)
    {
        foreach ($methods as $method) {
            $this->methods[$method->id()] = $method;
        }
    }

    /** @return list<VerificationMethodInterface> */
    public function availableFor(Project $project, bool $livemode): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn (VerificationMethodInterface $m): bool => $m->isAvailable($livemode)
                && ($m->id() === Methods\MockProvider::ID || in_array($m->id(), $project->methods, true)),
        ));
    }

    public function find(Project $project, bool $livemode, string $id): ?VerificationMethodInterface
    {
        foreach ($this->availableFor($project, $livemode) as $method) {
            if ($method->id() === $id) {
                return $method;
            }
        }

        return null;
    }
}
