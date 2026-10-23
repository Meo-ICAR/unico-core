<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Unico\Core\Concerns\UsesCoreConnection;

/**
 * Ruoli di dipendenti e collaboratori, a cui si associano i permessi. Ogni ruolo è anche l'ufficio che risponde delle attività
 * (codice, email, responsabile): chi lo ricopre lo indica in `employee_roles` di dipendenti ed esterni.
 */
class EmployeeType extends Model
{
    use UsesCoreConnection;

    protected $table = 'employee_types';

    protected $guarded = ['id'];

    /**
     * Codice dell'ufficio e nome del ruolo che lo ricopre (anche delle vecchie funzioni aziendali dei processi).
     *
     * @var array<string, string>
     */
    public const OFFICE_ROLES = [
        'COMPLIANCE' => 'compliance',
        'HR' => 'risorse umane',
        'LEGAL' => 'legale',
        'IT' => 'it',
        'BUS-BO' => 'back office',
        'CTRL-AML' => 'antiriciclaggio',
        'BUS-RETE-EXT' => 'rete collaboratori',
        'CTRL-COMPL' => 'controllo compliance',
        'COGE-CONTABILE' => 'contabile',
        'COGE-CONTABILE-RESP' => 'contabile responsabile',
        'COGE-CONSULENTE' => 'consulente coge',
        'COGE-AMMINISTRATORE' => 'amministratore',
        'SEGRETERIA' => 'segreteria amministrativa',
        'WHISTLEBLOWING' => 'responsabile whistleblowing',
        'WHISTLEBLOWING-SUPPLENTI' => 'supplente whistleblowing',
        'CDA' => 'cda',
        'AD' => 'amministratore delegato',
        'AFFARI' => 'affari societari e contabilità',
        'ORG-RU' => 'organizzazione e risorse umane',
        'COMMERCIALE' => 'commerciale',
        'SEGR-RETE' => 'segreteria di rete',
        'INT-AUDIT' => 'audit',
        'DPO' => 'dpo',
    ];

    /**
     * Valori di `outsourcable_status`: «yes» e «partial» permettono di assegnare il ruolo anche a un esterno (client).
     *
     * @var array<string, string>
     */
    public const OUTSOURCABLE = [
        'no' => 'No',
        'partial' => 'Parziale',
        'yes' => 'Sì',
    ];

    /**
     * Ruolo dell'ufficio con questo codice: lo crea se manca (nome da OFFICE_ROLES, altrimenti il nome dell'ufficio in minuscolo)
     * e dà il codice a un ruolo che esisteva già con quel nome. Il nome dell'ufficio (es. «Ufficio Compliance») diventa la
     * descrizione se non ce n'è una; gli altri attributi aggiornano il ruolo.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function office(string $code, array $attributes = []): self
    {
        $officeName = (string) ($attributes['name'] ?? $code);
        $roleName = self::OFFICE_ROLES[$code] ?? mb_strtolower($officeName);
        unset($attributes['name']);

        $role = static::query()->where('code', $code)->first()
            ?? static::query()->where('name', $roleName)->first()
            ?? new static(['name' => $roleName]);

        $role->fill(['code' => $code] + $attributes);
        $role->description ??= $officeName;
        $role->save();

        return $role;
    }

    /** Il ruolo può essere ricoperto anche da un esterno (client). */
    public function isOutsourcable(): bool
    {
        return in_array($this->outsourcable_status, ['yes', 'partial'], true);
    }

    /** Dipendenti che ricoprono il ruolo (`employee_roles`). */
    public function employees(): Builder
    {
        return Employee::query()->whereJsonContains('employee_roles', $this->name);
    }

    /** Esterni (client) che ricoprono il ruolo: solo per i ruoli esternalizzabili. */
    public function clients(): Builder
    {
        return Client::query()->whereJsonContains('employee_roles', $this->name);
    }

    /** Rinomina il ruolo anche in `employee_roles` di chi lo ricopre (lì è scritto per nome). */
    public function renameTo(string $name): void
    {
        if ($this->name === $name) {
            return;
        }

        self::replaceHolderRole($this->name, $name);
        $this->update(['name' => $name]);
    }

    protected static function replaceHolderRole(string $from, string $to): void
    {
        foreach ([Employee::class, Client::class] as $model) {
            $model::query()->whereJsonContains('employee_roles', $from)->get()->each(fn ($holder) => $holder->update([
                'employee_roles' => collect($holder->employee_roles)->map(fn (string $role): string => $role === $from ? $to : $role)->unique()->values()->all(),
            ]));
        }
    }

    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
        ];
    }

    /** Responsabile dell'ufficio: un dipendente o, per i ruoli esternalizzabili, un esterno. */
    public function manager(): MorphTo
    {
        return $this->morphTo();
    }

    public function employeeTypePermissions(): HasMany
    {
        return $this->hasMany(EmployeeTypePermission::class, 'employee_type_id');
    }

    public function employeeTypeResourcePresets(): HasMany
    {
        return $this->hasMany(EmployeeTypeResourcePreset::class, 'employee_type_id');
    }
}
