<?php

namespace App\Services\Ai;

use App\Exceptions\AiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, dependency-free wrapper over the OpenAI API — the Titan coach's language
 * + vision brain. Ported from fullstack-suite. Powers chat, JSON extraction,
 * tool-calling agent loops, vision (meal + progress photos), embeddings (semantic
 * search over each profile's health wiki), and Whisper transcription (voice notes).
 *
 * Plain Http on purpose: no SDK to keep in lockstep.
 */
class AiService
{
    /** Running token usage since the last reset (per model, so cost can be priced). */
    public array $usage = ['prompt' => 0, 'completion' => 0, 'calls' => 0, 'models' => []];

    public function configured(): bool
    {
        return (bool) config('services.openai.key');
    }

    /** GPT-5 / o-series use `max_completion_tokens`; older models use `max_tokens`. */
    private function tokenLimitParam(string $model): string
    {
        $m = strtolower($model);

        return (str_starts_with($m, 'gpt-5') || preg_match('/^o\d/', $m)) ? 'max_completion_tokens' : 'max_tokens';
    }

    public function resetUsage(): void
    {
        $this->usage = ['prompt' => 0, 'completion' => 0, 'calls' => 0, 'models' => []];
    }

    /** Accumulate token usage from an OpenAI response (aggregate + per model). */
    private function track($response): void
    {
        $u = $response->json('usage') ?? [];
        $pt = (int) ($u['prompt_tokens'] ?? 0);
        $ct = (int) ($u['completion_tokens'] ?? 0);
        $this->usage['prompt'] += $pt;
        $this->usage['completion'] += $ct;
        $this->usage['calls']++;

        $model = (string) ($response->json('model') ?? 'unknown');
        $this->usage['models'][$model] ??= ['prompt' => 0, 'completion' => 0];
        $this->usage['models'][$model]['prompt'] += $pt;
        $this->usage['models'][$model]['completion'] += $ct;
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.openai.base_url'), '/').$path;
    }

    /**
     * Run a chat completion and return the assistant's text.
     *
     * `messages` may include vision content: a message's `content` can be an array
     * of parts like [['type'=>'text','text'=>...], ['type'=>'image_url','image_url'=>['url'=>...]]].
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array{model?:string,temperature?:float,max_tokens?:int,json?:bool,timeout?:int}  $opts
     */
    public function chat(array $messages, array $opts = []): string
    {
        if (! $this->configured()) {
            throw new AiException('OpenAI is not configured (missing OPENAI_API_KEY).');
        }

        $model = $opts['model'] ?? config('services.openai.chat_model');
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $opts['temperature'] ?? 0.7,
        ];
        if (isset($opts['max_tokens'])) {
            $payload[$this->tokenLimitParam($model)] = $opts['max_tokens'];
        }
        if (! empty($opts['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout((int) ($opts['timeout'] ?? config('services.openai.timeout', 60)))
                ->acceptJson()
                ->post($this->endpoint('/chat/completions'), $payload);
        } catch (\Throwable $e) {
            throw new AiException('Could not reach OpenAI: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            Log::warning('[AI] OpenAI request failed', ['status' => $response->status(), 'detail' => $detail]);
            throw new AiException('OpenAI request failed: '.$detail);
        }

        $this->track($response);

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new AiException('OpenAI returned an empty response.');
        }

        return trim($content);
    }

    /**
     * Run a completion that must return a JSON object, decoded to an array.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array{model?:string,temperature?:float,max_tokens?:int}  $opts
     * @return array<string,mixed>
     */
    public function json(array $messages, array $opts = []): array
    {
        $raw = $this->chat($messages, $opts + ['json' => true]);
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new AiException('OpenAI did not return valid JSON.');
        }

        return $decoded;
    }

    /**
     * Vision convenience: ask a question about one or more images. Images are
     * passed as data URLs or https URLs. Returns the model's text answer.
     *
     * @param  array<int,string>  $imageUrls  data: or https: URLs
     * @param  array{model?:string,temperature?:float,max_tokens?:int,json?:bool,detail?:string}  $opts
     */
    public function vision(string $prompt, array $imageUrls, array $opts = []): string
    {
        $parts = [['type' => 'text', 'text' => $prompt]];
        foreach ($imageUrls as $url) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => $opts['detail'] ?? 'auto']];
        }

        return $this->chat(
            [['role' => 'user', 'content' => $parts]],
            ['model' => $opts['model'] ?? config('services.openai.vision_model')] + $opts,
        );
    }

    /**
     * Run an agent loop with tool/function calling: the model may call our tools,
     * we run them and feed results back, until it returns a final text answer.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array<string,mixed>>  $tools  OpenAI tool schemas
     * @param  callable(string,array):mixed  $dispatch  runs a tool, returns its result
     * @param  array{model?:string,temperature?:float,max_steps?:int}  $opts
     */
    public function chatWithTools(array $messages, array $tools, callable $dispatch, array $opts = []): string
    {
        if (! $this->configured()) {
            throw new AiException('OpenAI is not configured (missing OPENAI_API_KEY).');
        }

        $maxSteps = (int) ($opts['max_steps'] ?? 6);

        for ($step = 0; $step < $maxSteps; $step++) {
            $payload = [
                'model' => $opts['model'] ?? config('services.openai.chat_model'),
                'messages' => $messages,
                'temperature' => $opts['temperature'] ?? 0.4,
            ];
            if ($tools !== []) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $message = $this->completion($payload);
            $messages[] = $message;

            $toolCalls = $message['tool_calls'] ?? [];
            if ($toolCalls === []) {
                return trim((string) ($message['content'] ?? ''));
            }

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true);
                try {
                    $result = $dispatch($name, is_array($args) ? $args : []);
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'content' => is_string($result) ? $result : json_encode($result),
                ];
            }
        }

        return trim((string) ($messages[array_key_last($messages)]['content'] ?? ''));
    }

    /**
     * Streaming sibling of chatWithTools(): runs the same tool-calling loop, but streams
     * the final answer's tokens through $onDelta as they arrive, and announces each tool
     * the model decides to call through $onToolCall (so the UI can show "Reading your day…").
     *
     * Returns the full assembled answer text (same as chatWithTools), so the caller can
     * persist it. Falls back transparently to a non-streamed round if streaming isn't
     * possible for a given step.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array<string,mixed>>  $tools
     * @param  callable(string,array):mixed  $dispatch
     * @param  callable(string):void  $onDelta        receives each content token
     * @param  callable(string,array):void|null  $onToolCall  receives (toolName, args) before a tool runs
     * @param  array{model?:string,temperature?:float,max_steps?:int}  $opts
     */
    public function chatWithToolsStreaming(
        array $messages,
        array $tools,
        callable $dispatch,
        callable $onDelta,
        ?callable $onToolCall = null,
        array $opts = [],
    ): string {
        if (! $this->configured()) {
            throw new AiException('OpenAI is not configured (missing OPENAI_API_KEY).');
        }

        $maxSteps = (int) ($opts['max_steps'] ?? 6);

        for ($step = 0; $step < $maxSteps; $step++) {
            $payload = [
                'model' => $opts['model'] ?? config('services.openai.chat_model'),
                'messages' => $messages,
                'temperature' => $opts['temperature'] ?? 0.4,
            ];
            if ($tools !== []) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $message = $this->streamCompletion($payload, $onDelta);
            $messages[] = $message;

            $toolCalls = $message['tool_calls'] ?? [];
            if ($toolCalls === []) {
                return trim((string) ($message['content'] ?? ''));
            }

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true);
                $args = is_array($args) ? $args : [];
                if ($onToolCall !== null && $name !== '') {
                    try {
                        $onToolCall($name, $args);
                    } catch (\Throwable) {
                        // a UI hiccup must never break the loop
                    }
                }
                try {
                    $result = $dispatch($name, $args);
                } catch (\Throwable $e) {
                    $result = ['error' => $e->getMessage()];
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'content' => is_string($result) ? $result : json_encode($result),
                ];
            }
        }

        return trim((string) ($messages[array_key_last($messages)]['content'] ?? ''));
    }

    /**
     * One streamed chat-completions round. Reads the OpenAI SSE stream, calls $onDelta for
     * each content token, reassembles streamed tool-call fragments (by index), and returns
     * the finished assistant message array — exactly the shape completion() returns.
     *
     * @param  array<string,mixed>  $payload
     * @param  callable(string):void  $onDelta
     * @return array<string,mixed>
     */
    protected function streamCompletion(array $payload, callable $onDelta): array
    {
        $payload['stream'] = true;
        $payload['stream_options'] = ['include_usage' => true];

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout((int) config('services.openai.timeout', 120))
                ->withOptions(['stream' => true])
                ->withHeaders(['Accept' => 'text/event-stream'])
                ->post($this->endpoint('/chat/completions'), $payload);
        } catch (\Throwable $e) {
            throw new AiException('Could not reach OpenAI: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            Log::warning('[AI] OpenAI stream request failed', ['status' => $response->status(), 'detail' => $detail]);
            throw new AiException('OpenAI request failed: '.$detail);
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $content = '';
        $toolCalls = [];   // index => ['id','type','function'=>['name','arguments']]
        $usage = null;

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            // SSE frames are newline-delimited; process every complete line we have.
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nl));
                $buffer = substr($buffer, $nl + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    break 2;
                }
                $json = json_decode($data, true);
                if (! is_array($json)) {
                    continue;
                }
                if (isset($json['usage'])) {
                    $usage = $json['usage'];
                }
                $delta = $json['choices'][0]['delta'] ?? [];

                if (isset($delta['content']) && $delta['content'] !== '' && $delta['content'] !== null) {
                    $content .= $delta['content'];
                    $onDelta($delta['content']);
                }
                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    $i = $tc['index'] ?? 0;
                    $toolCalls[$i] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    if (! empty($tc['id'])) {
                        $toolCalls[$i]['id'] = $tc['id'];
                    }
                    if (isset($tc['function']['name'])) {
                        $toolCalls[$i]['function']['name'] .= $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $toolCalls[$i]['function']['arguments'] .= $tc['function']['arguments'];
                    }
                }
            }
        }

        if (is_array($usage)) {
            $pt = (int) ($usage['prompt_tokens'] ?? 0);
            $ct = (int) ($usage['completion_tokens'] ?? 0);
            $this->usage['prompt'] += $pt;
            $this->usage['completion'] += $ct;
            $this->usage['calls']++;
        }

        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = array_values($toolCalls);
        }

        return $message;
    }

    /**
     * One chat-completions round; returns the assistant message (incl. tool_calls).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function completion(array $payload): array
    {
        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout((int) config('services.openai.timeout', 60))
                ->acceptJson()
                ->post($this->endpoint('/chat/completions'), $payload);
        } catch (\Throwable $e) {
            throw new AiException('Could not reach OpenAI: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            Log::warning('[AI] OpenAI tool request failed', ['status' => $response->status(), 'detail' => $detail]);
            throw new AiException('OpenAI request failed: '.$detail);
        }

        $this->track($response);

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new AiException('OpenAI returned no message.');
        }

        return $message;
    }

    /**
     * Embed one or more texts into vectors for semantic search. Returns a list of
     * float vectors aligned to the input order. Powers the health-wiki search.
     *
     * @param  array<int,string>  $texts
     * @return array<int,array<int,float>>
     */
    public function embed(array $texts, array $opts = []): array
    {
        if (! $this->configured()) {
            throw new AiException('OpenAI is not configured (missing OPENAI_API_KEY).');
        }
        if ($texts === []) {
            return [];
        }

        // The API rejects empty strings — substitute a single space placeholder.
        $input = array_map(fn ($t) => trim((string) $t) === '' ? ' ' : (string) $t, array_values($texts));

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout((int) ($opts['timeout'] ?? config('services.openai.timeout', 60)))
                ->acceptJson()
                ->post($this->endpoint('/embeddings'), [
                    'model' => $opts['model'] ?? config('services.openai.embed_model', 'text-embedding-3-small'),
                    'input' => $input,
                ]);
        } catch (\Throwable $e) {
            throw new AiException('Could not reach OpenAI: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            Log::warning('[AI] embeddings request failed', ['status' => $response->status(), 'detail' => $detail]);
            throw new AiException('OpenAI embeddings failed: '.$detail);
        }

        $this->track($response);

        $data = $response->json('data') ?? [];
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(fn ($row) => array_map('floatval', $row['embedding'] ?? []), $data);
    }

    /** Embed a single text; convenience wrapper over embed(). */
    public function embedOne(string $text, array $opts = []): array
    {
        return $this->embed([$text], $opts)[0] ?? [];
    }

    /**
     * Transcribe audio bytes to text (Whisper). Returns null on any failure —
     * used for voice notes to the coach.
     */
    public function transcribe(string $bytes, string $filename = 'audio.ogg'): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(60)
                ->attach('file', $bytes, $filename)
                ->post($this->endpoint('/audio/transcriptions'), [
                    'model' => config('services.openai.transcribe_model', 'whisper-1'),
                ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            Log::warning('[AI] transcription failed', ['status' => $response->status()]);

            return null;
        }

        $text = $response->json('text');

        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
