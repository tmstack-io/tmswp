<?php

declare(strict_types=1);

namespace TMSWP\Core\Form;

use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Form\Composite\CompositeField;

final class Form
{
    public const FIELD_FORM_ID = '_form_id';
    public const FIELD_STEP = '_step';
    public const FIELD_NONCE = '_nonce';
    public const FIELD_CONFIRM_TOKEN = '_confirm_token';

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, mixed> */
    private array $defaultOverrides = [];

    /** @var array<string, list<string>> */
    private array $fieldErrors = [];

    /** @var list<string> */
    private array $formErrors = [];

    private FormStep $step = FormStep::Input;

    private ?FormResult $result = null;

    private string $confirmToken = '';

    public function __construct(private readonly FormDefinition $definition)
    {
        $this->values = $this->defaultValues();
    }

    public function handle(?FormRequest $request = null): FormResult
    {
        if ($this->result instanceof FormResult) {
            return $this->result;
        }

        $request ??= FormRequest::fromGlobals();

        if (!$request->isPost() || $this->requestString($request, self::FIELD_FORM_ID) !== $this->definition->id) {
            $this->values = $this->defaultValues();

            return $this->finish(FormStep::Input);
        }

        if (!$this->verifyNonce($request)) {
            $this->bind($request);
            $this->formErrors[] = $this->translate('Your session has expired. Please submit the form again.');

            return $this->finish(FormStep::Input);
        }

        $action = FormAction::tryFrom($this->requestString($request, self::FIELD_STEP));

        if (!$action instanceof FormAction) {
            $this->bind($request);

            return $this->finish(FormStep::Input);
        }

        if ($action === FormAction::Confirm && !$this->definition->isConfirmEnabled()) {
            $action = FormAction::Submit;
        }

        return match ($action) {
            FormAction::Confirm => $this->handleConfirm($request),
            FormAction::Submit => $this->handleSubmit($request),
            FormAction::Back => $this->handleBack($request),
        };
    }

    public function field(string $name): FieldView
    {
        $field = $this->definition->field($name);

        return new FieldView($field, $this->values[$name] ?? '', $this->fieldErrors[$name] ?? [], $this->definition->id, $this->definition->errorTemplate());
    }

    /** @return array<string, FieldView> */
    public function fields(): array
    {
        $views = [];

        foreach ($this->definition->fields() as $name => $_field) {
            $views[$name] = $this->field($name);
        }

        return $views;
    }

    /** @param array<string, mixed> $attrs */
    public function open(array $attrs = []): string
    {
        $attrs = ['method' => 'post', 'action' => ''] + $attrs;

        if ($this->definition->hasFileField()) {
            $attrs['enctype'] = 'multipart/form-data';
        }

        $html = FormHtml::openTag($attrs);
        $html .= FormHtml::hidden(self::FIELD_FORM_ID, $this->definition->id);
        $html .= FormHtml::hidden(self::FIELD_NONCE, $this->createNonce());

        if ($this->step === FormStep::Confirm) {
            $html .= FormHtml::hidden(self::FIELD_CONFIRM_TOKEN, $this->confirmToken);
        }

        return $html;
    }

    public function close(): string
    {
        return '</form>';
    }

    public function step(): FormStep
    {
        return $this->step;
    }

    public function id(): string
    {
        return $this->definition->id;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values */
    public function applyDefaults(array $values): void
    {
        foreach ($values as $name => $value) {
            if (!$this->definition->has($name)) {
                throw new FormConfigException("unknown form field default: {$name}");
            }

            $this->defaultOverrides[$name] = $value;
        }

        $this->values = $this->defaultValues();
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->fieldErrors;
    }

    /** @return list<string> */
    public function formErrors(): array
    {
        return $this->formErrors;
    }

    public function hasErrors(): bool
    {
        return $this->fieldErrors !== [] || $this->formErrors !== [];
    }

    private function handleConfirm(FormRequest $request): FormResult
    {
        $this->bind($request);
        $this->validate();

        if ($this->fieldErrors !== []) {
            return $this->finish(FormStep::Input);
        }

        $this->storeUploadedFiles();
        $this->confirmToken = ConfirmToken::issue($this->definition->id, $this->tokenValues());

        return $this->finish(FormStep::Confirm);
    }

    private function handleSubmit(FormRequest $request): FormResult
    {
        if ($this->definition->isConfirmEnabled()) {
            $token = ConfirmToken::verify($this->definition->id, $this->requestString($request, self::FIELD_CONFIRM_TOKEN), $this->definition->ttl());

            if ($token->status === ConfirmTokenStatus::Invalid) {
                $this->values = $this->defaultValues();
                $this->formErrors[] = $this->translate('The form could not be resubmitted. Please start again.');

                return $this->finish(FormStep::Input);
            }

            if ($token->status === ConfirmTokenStatus::Expired) {
                $this->restoreValues($token->values);
                $this->formErrors[] = $this->translate('The confirmation screen has expired. Please review your entries and continue again.');

                return $this->finish(FormStep::Input);
            }

            if (!$this->consumeJti($token->jti, $this->definition->ttl() + 60)) {
                $this->values = $this->defaultValues();
                $this->formErrors[] = $this->translate('This form has already been submitted.');

                return $this->finish(FormStep::Input);
            }

            if (!$this->restoreFileTokens($token->values)) {
                $this->formErrors[] = $this->translate('The confirmation screen has expired. Please review your entries and continue again.');

                return $this->finish(FormStep::Input);
            }
        } else {
            $this->bind($request);
            $this->validate();

            if ($this->fieldErrors !== []) {
                return $this->finish(FormStep::Input);
            }
        }

        $handler = $this->definition->completeHandler();

        if ($handler !== null) {
            $handler($this->values, $this);
        }

        return $this->finish(FormStep::Complete);
    }

    private function handleBack(FormRequest $request): FormResult
    {
        $token = ConfirmToken::verify($this->definition->id, $this->requestString($request, self::FIELD_CONFIRM_TOKEN), $this->definition->ttl());

        if ($token->status === ConfirmTokenStatus::Invalid) {
            $this->values = $this->defaultValues();

            return $this->finish(FormStep::Input);
        }

        $this->restoreValues($token->values);

        return $this->finish(FormStep::Input);
    }

    private function bind(FormRequest $request): void
    {
        $values = [];

        foreach ($this->definition->fields() as $name => $field) {
            if ($field instanceof CompositeField) {
                $raw = $request->post($name);
                $values[$name] = \is_array($raw) ? $raw : [];
                continue;
            }

            $values[$name] = $this->bindField($field, $request);
        }

        $this->values = $values;
    }

    private function bindField(Field $field, FormRequest $request): mixed
    {
        if ($field->type === FieldType::File) {
            $file = $request->files[$field->name] ?? null;

            if (\is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                return UploadedFile::fromFilesEntry($this->fileEntry($file));
            }

            $token = $this->requestString($request, $field->name . '__token');

            if ($token !== '' && $this->definition->store()->exists($token)) {
                return $this->definition->store()->resolve($token);
            }

            return '';
        }

        $raw = $request->post($field->name);

        if ($field->multiple) {
            $items = \is_array($raw) ? $raw : ($raw === '' ? [] : [$raw]);
            $values = [];

            foreach ($items as $item) {
                $value = $this->normalizeScalar($item);

                foreach ($field->filters as $filter) {
                    $value = $filter($value);
                }

                $values[] = $value;
            }

            return $values;
        }

        $value = $this->normalizeScalar($raw);

        foreach ($field->filters as $filter) {
            $value = $filter($value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function fileEntry(array $file): array
    {
        if (
            !\is_string($file['name'] ?? null)
            || !\is_string($file['type'] ?? null)
            || !\is_string($file['tmp_name'] ?? null)
            || !\is_int($file['error'] ?? null)
            || !\is_int($file['size'] ?? null)
        ) {
            throw new FormConfigException('uploaded file entry is invalid');
        }

        return [
            'name' => $file['name'],
            'type' => $file['type'],
            'tmp_name' => $file['tmp_name'],
            'error' => $file['error'],
            'size' => $file['size'],
        ];
    }

    private function normalizeScalar(mixed $value): string
    {
        return Validator::stringValue($value) ?? '';
    }

    private function validate(): void
    {
        $this->fieldErrors = (new Validator())->validate($this->definition, $this->values);

        if ($this->fieldErrors === []) {
            $this->normalizeCompositeValues();
        }
    }

    private function storeUploadedFiles(): void
    {
        foreach ($this->values as $name => $value) {
            if ($value instanceof UploadedFile) {
                $this->values[$name] = $this->definition->store()->store($value);
            }
        }
    }

    /** @return array<string, string|list<string>|array{__tmsfile: string}> */
    private function tokenValues(): array
    {
        $values = [];

        foreach ($this->values as $name => $value) {
            if ($value instanceof StoredFile) {
                $values[$name] = ['__tmsfile' => $value->token];
                continue;
            }

            if ($value instanceof UploadedFile) {
                throw new FormConfigException('UploadedFile must be stored before issuing confirm token');
            }

            if (\is_array($value)) {
                if (!array_is_list($value)) {
                    throw new FormConfigException("field '{$name}' must be normalized before issuing confirm token");
                }

                $items = [];

                foreach ($value as $item) {
                    $string = Validator::stringValue($item);

                    if ($string === null) {
                        throw new FormConfigException("field '{$name}' contains a non-stringable value");
                    }

                    $items[] = $string;
                }

                $values[$name] = $items;
                continue;
            }

            $string = Validator::stringValue($value);

            if ($string === null) {
                throw new FormConfigException("field '{$name}' contains a non-stringable value");
            }

            $values[$name] = $string;
        }

        return $values;
    }

    /** @param array<string, string|list<string>|array{__tmsfile: string}> $values */
    private function restoreValues(array $values): void
    {
        $this->values = $this->defaultValues();

        foreach ($values as $name => $value) {
            if (!$this->definition->has($name)) {
                throw new FormConfigException("unknown form field in confirm token: {$name}");
            }

            $field = $this->definition->field($name);

            if (\is_array($value) && \array_key_exists('__tmsfile', $value)) {
                $this->values[$name] = $this->definition->store()->exists($value['__tmsfile'])
                    ? $this->definition->store()->resolve($value['__tmsfile'])
                    : '';
                continue;
            }

            $this->values[$name] = $field instanceof CompositeField && \is_string($value) ? $field->decompose($value) : $value;
        }
    }

    /** @param array<string, string|list<string>|array{__tmsfile: string}> $values */
    private function restoreFileTokens(array $values): bool
    {
        $this->values = $this->defaultValues();

        foreach ($values as $name => $value) {
            if (\is_array($value) && \array_key_exists('__tmsfile', $value)) {
                if (!$this->definition->has($name)) {
                    throw new FormConfigException("unknown form field in confirm token: {$name}");
                }

                if (!$this->definition->store()->exists($value['__tmsfile'])) {
                    $this->values[$name] = '';

                    return false;
                }

                $this->values[$name] = $this->definition->store()->resolve($value['__tmsfile']);
                continue;
            }

            if (!$this->definition->has($name)) {
                throw new FormConfigException("unknown form field in confirm token: {$name}");
            }

            $this->values[$name] = $value;
        }

        return true;
    }

    private function consumeJti(string $jti, int $ttl): bool
    {
        return (new JtiStore())->claim($jti, $ttl);
    }

    /** @return array<string, mixed> */
    private function defaultValues(): array
    {
        $values = [];

        foreach ($this->definition->fields() as $name => $field) {
            if ($field instanceof CompositeField) {
                $values[$name] = $field->decompose('');
                continue;
            }

            $values[$name] = $field->default;
        }

        foreach ($this->defaultOverrides as $name => $value) {
            $values[$name] = $value;
        }

        return $values;
    }

    private function finish(FormStep $step): FormResult
    {
        $this->step = $step;
        $this->result = new FormResult($step, $this->values, $this->fieldErrors, $this->formErrors);

        return $this->result;
    }

    private function translate(string $message): string
    {
        return \function_exists('__') ? __($message, 'tmswp') : $message;
    }

    private function verifyNonce(FormRequest $request): bool
    {
        if (!\function_exists('wp_verify_nonce')) {
            throw new FormConfigException('wp_verify_nonce is not available');
        }

        return wp_verify_nonce($this->requestString($request, self::FIELD_NONCE), 'tmswp_form_' . $this->definition->id) !== false;
    }

    private function createNonce(): string
    {
        if (!\function_exists('wp_create_nonce')) {
            throw new FormConfigException('wp_create_nonce is not available');
        }

        return wp_create_nonce('tmswp_form_' . $this->definition->id);
    }

    private function requestString(FormRequest $request, string $key): string
    {
        return Validator::stringValue($request->post($key)) ?? '';
    }

    private function normalizeCompositeValues(): void
    {
        foreach ($this->definition->fields() as $name => $field) {
            if (!$field instanceof CompositeField) {
                continue;
            }

            $value = $this->values[$name] ?? [];
            $parts = \is_array($value) ? $this->normalizeCompositeParts($field, $value) : $field->decompose(Validator::stringValue($value) ?? '');
            $this->values[$name] = $this->compositePartsAreEmpty($parts) ? '' : $field->compose($parts);
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string>
     */
    private function normalizeCompositeParts(CompositeField $field, array $raw): array
    {
        $values = [];

        foreach ($field->parts() as $partName => $part) {
            $value = Validator::stringValue($raw[$partName] ?? '') ?? '';

            foreach ($part->filters as $filter) {
                $value = $filter($value);
            }

            $values[$partName] = $value;
        }

        return $values;
    }

    /** @param array<string, string> $parts */
    private function compositePartsAreEmpty(array $parts): bool
    {
        foreach ($parts as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }
}
