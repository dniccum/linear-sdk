<?php

declare(strict_types=1);

namespace Dniccum\Linear\Services;

use BackedEnum;
use Carbon\CarbonInterface;
use Dniccum\Linear\Contracts\ComposesLinearIssue;
use Dniccum\Linear\Support\Json;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonSerializable;
use Stringable;

/**
 * Renders models as Linear issue and comment markdown.
 *
 * The title(), description() and comment() methods honour the hooks of
 * {@see ComposesLinearIssue} when the model has
 * them (the `CreatesLinearIssues` trait provides defaults that call the
 * default*() methods below).
 */
class IssueComposer
{
    /**
     * Attributes never included in a generated description.
     *
     * @var list<string>
     */
    protected const array EXCLUDED = ['password', 'remember_token', 'api_token', 'access_token', 'refresh_token'];

    /**
     * Attributes tried, in order, as a generated title.
     *
     * @var list<string>
     */
    protected const array TITLE_ATTRIBUTES = ['title', 'subject', 'name'];

    /**
     * Linear caps issue titles at 255 characters.
     */
    public function title(Model $model): string
    {
        $title = method_exists($model, 'linearTitle') ? Json::string($model->linearTitle()) : $this->defaultTitle($model);

        return Str::limit(trim($title), 250);
    }

    public function description(Model $model): string
    {
        return method_exists($model, 'linearDescription') ? Json::string($model->linearDescription()) : $this->defaultDescription($model);
    }

    /**
     * The comment posted for a lifecycle event.
     *
     * @param  array<string, mixed>  $context
     */
    public function comment(Model $model, string $event, array $context = []): string
    {
        return method_exists($model, 'linearComment')
            ? Json::string($model->linearComment($event, $context))
            : $this->defaultComment($model, $event, $context);
    }

    /**
     * The first of title, subject or name that is filled in, falling back to
     * "Model #key".
     */
    public function defaultTitle(Model $model): string
    {
        foreach (self::TITLE_ATTRIBUTES as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->label($model);
    }

    /**
     * A header naming the record followed by its attributes: the visible
     * attributes if the model declares any, else its fillable ones, else all
     * of them. Hidden attributes and credentials are never included.
     */
    public function defaultDescription(Model $model): string
    {
        $sections = ['**'.$this->label($model).'**'];

        $details = $this->details($model, $this->attributeNames($model));

        if ($details !== []) {
            $sections[] = "**Details**\n".implode("\n", $details);
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  array<string, mixed>  $context  `changes` maps changed attributes to their new values.
     */
    public function defaultComment(Model $model, string $event, array $context = []): string
    {
        $comment = sprintf('**%s** was %s.', $this->label($model), $event);

        $changes = Json::map($context['changes'] ?? null);
        $details = $this->details($model, array_keys($changes), $changes);

        return $details === [] ? $comment : $comment."\n\n".implode("\n", $details);
    }

    /**
     * "Support Request #12": the model's basename and key.
     */
    public function label(Model $model): string
    {
        return Str::headline(class_basename($model)).' #'.Json::string($model->getKey());
    }

    /**
     * @return list<string>
     */
    protected function attributeNames(Model $model): array
    {
        $names = match (true) {
            $model->getVisible() !== [] => $model->getVisible(),
            $model->getFillable() !== [] => $model->getFillable(),
            default => array_keys($model->getAttributes()),
        };

        return array_values(array_diff(
            Json::strings($names),
            $model->getHidden(),
            self::EXCLUDED,
            [$model->getKeyName()],
        ));
    }

    /**
     * @param  list<string>  $attributes
     * @param  array<string, mixed>|null  $values  Read these instead of the model's attributes.
     * @return list<string>
     */
    protected function details(Model $model, array $attributes, ?array $values = null): array
    {
        $lines = [];

        foreach ($attributes as $attribute) {
            if (in_array($attribute, self::EXCLUDED, true) || in_array($attribute, $model->getHidden(), true)) {
                continue;
            }

            $value = $this->format($values !== null ? ($values[$attribute] ?? null) : $model->getAttribute($attribute));

            if ($value === null) {
                continue;
            }

            $label = Str::headline($attribute);

            $lines[] = str_contains($value, "\n")
                ? "- {$label}:\n".$this->quote($value)
                : "- {$label}: {$value}";
        }

        return $lines;
    }

    /**
     * A printable form of an attribute value, or null for blank and
     * unprintable ones.
     */
    protected function format(mixed $value): ?string
    {
        $formatted = match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof CarbonInterface => $value->toIso8601String(),
            $value instanceof BackedEnum => (string) $value->value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            is_array($value), $value instanceof Arrayable, $value instanceof JsonSerializable => json_encode($value),
            default => null,
        };

        return is_string($formatted) && trim($formatted) !== '' ? trim($formatted) : null;
    }

    protected function quote(string $text): string
    {
        return implode("\n", array_map(
            fn (string $line): string => rtrim('  > '.$line),
            explode("\n", str_replace(["\r\n", "\r"], "\n", $text)),
        ));
    }
}
