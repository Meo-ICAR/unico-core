<?php

namespace Unico\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Unico\Core\Concerns\UsesCoreConnection;

/** Comune italiano (nome, provincia e codice catastale). */
class Comune extends Model
{
    use UsesCoreConnection;

    protected $table = 'comuni';

    protected $guarded = ['id'];

    public $timestamps = false;

    /** Il comune come si scrive nelle anagrafiche: «Polistena (RC)». */
    public function label(): string
    {
        return "{$this->name} ({$this->province})";
    }

    /**
     * Riconosce il comune da un testo scritto con o senza la provincia («POLISTENA», «Polistena (RC)»), senza badare a maiuscole,
     * accenti e apostrofi. Se il nome è lo stesso di più comuni e manca la provincia, o non c'è nessuna corrispondenza, restituisce
     * null: nel dubbio non si sceglie.
     */
    public static function resolve(?string $text): ?self
    {
        $text = trim((string) $text);
        $province = null;

        if (preg_match('/^(.+?)\s*\(\s*([A-Za-z]{2})\s*\)$/u', $text, $match) === 1) {
            $text = trim($match[1]);
            $province = strtoupper($match[2]);
        }

        if ($text === '') {
            return null;
        }

        $matches = static::query()->where('name', 'like', static::likePattern($text))->get()
            ->filter(fn (self $comune): bool => static::normalize($comune->name) === static::normalize($text))
            ->when($province !== null, fn ($found) => $found->where('province', $province))
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** Minuscolo, senza accenti, apostrofi, trattini e spazi multipli: «Sant'Antonio» e «SANT ANTONIO» coincidono. */
    public static function normalize(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))) ?? '');
    }

    /** Filtro largo sul nome (le lettere accentate o l'apostrofo possono differire): si restringe poi con normalize(). */
    private static function likePattern(string $text): string
    {
        return '%'.preg_replace('/[^A-Za-z0-9]+/', '%', Str::ascii($text)).'%';
    }

    public function scopeInProvince(Builder $query, string $province): Builder
    {
        return $query->where('province', strtoupper($province));
    }
}
