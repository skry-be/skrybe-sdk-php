<?php

namespace Skrybe\Resource;

use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

/**
 * A template has id, name, from_name, from_email, reply_to and editor (html or
 * dragdrop, the editor the dashboard opens it in); html_text and plain_text
 * only from get() with $includeContent, and from create() and update().
 *
 * Template fields, all optional except where noted: name, htmlText,
 * plainText, fromName, fromEmail, replyTo, editor.
 */
final class Templates
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The brand's templates by name, without their HTML.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $text = $this->transport->text('api/templates/get-templates.php', retryable: true);
        return Parse::jsonObject($text, 'templates')['templates'] ?? [];
    }

    /** @return array<string, mixed> */
    public function get(int $templateId, bool $includeContent = false): array
    {
        $text = $this->transport->text('api/templates/get-template.php', [
            'template_id' => $templateId,
            'include_content' => $includeContent ? 'yes' : null,
        ], retryable: true);
        return Parse::jsonObject($text, 'template');
    }

    /**
     * Not retried: a repeat would create a second template.
     *
     * @param array{name: string, htmlText: string, plainText?: string, fromName?: string, fromEmail?: string, replyTo?: string, editor?: 'html'|'dragdrop'} $fields
     * @return array<string, mixed>
     */
    public function create(array $fields): array
    {
        Errors::requireParams($fields, ['name', 'htmlText']);
        $text = $this->transport->text('api/templates/create-template.php', self::fieldsBody($fields));
        return Parse::jsonObject($text, 'template');
    }

    /**
     * Only the fields given change.
     *
     * @param array{name?: string, htmlText?: string, plainText?: string, fromName?: string, fromEmail?: string, replyTo?: string, editor?: 'html'|'dragdrop'} $fields
     * @return array<string, mixed>
     */
    public function update(int $templateId, array $fields): array
    {
        $text = $this->transport->text('api/templates/update-template.php', ['template_id' => $templateId] + self::fieldsBody($fields));
        return Parse::jsonObject($text, 'template');
    }

    /**
     * Campaigns already made from it are unaffected.
     *
     * @return array{template_id: int, deleted: true}
     */
    public function delete(int $templateId): array
    {
        $text = $this->transport->text('api/templates/delete-template.php', ['template_id' => $templateId]);
        return Parse::jsonObject($text, 'delete result');
    }

    /** @return array<string, ?string> */
    private static function fieldsBody(array $fields): array
    {
        return [
            'name' => $fields['name'] ?? null,
            'html_text' => $fields['htmlText'] ?? null,
            'plain_text' => $fields['plainText'] ?? null,
            'from_name' => $fields['fromName'] ?? null,
            'from_email' => $fields['fromEmail'] ?? null,
            'reply_to' => $fields['replyTo'] ?? null,
            'editor' => $fields['editor'] ?? null,
        ];
    }
}
