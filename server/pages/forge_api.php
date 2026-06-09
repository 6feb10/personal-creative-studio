<?php
// ═══════════════════════════════════════════════
//  Forge API Helper v2 — 複数モデル対応
// ═══════════════════════════════════════════════

function forgeCallAPI(PDO $pdo, int $providerId, string $systemPrompt, string $userPrompt): array {
    $provider = $pdo->prepare("SELECT * FROM api_providers WHERE id=? AND enabled=1");
    $provider->execute([$providerId]);
    $provider = $provider->fetch();

    if (!$provider) return ['error' => 'プロバイダーが未設定または無効です'];
    if (!$provider['api_key']) return ['error' => $provider['display_name'] . ' のAPIキーが未設定です'];

    $apiKey = $provider['api_key'];
    $model = $provider['model'];
    $endpoint = $provider['endpoint'];
    $type = $provider['provider_type'];

    switch ($type) {
        case 'OpenAI':
        case 'Grok':
        case 'Deepseek':
            return callOpenAICompatible($endpoint, $apiKey, $model, $systemPrompt, $userPrompt, $provider);

        case 'Claude':
            return callClaude($endpoint, $apiKey, $model, $systemPrompt, $userPrompt, $provider);

        case 'Gemini':
            return callGemini($endpoint, $apiKey, $model, $systemPrompt, $userPrompt, $provider);

        default:
            return ['error' => '未対応のプロバイダータイプ: ' . $type];
    }
}

function callOpenAICompatible(string $endpoint, string $apiKey, string $model, string $sys, string $user, array $provider): array {
    $body = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => 4096,
        'temperature' => 0.8,
    ], JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    $resp = curlPost($endpoint, $body, $headers);
    if (isset($resp['error'])) return $resp;

    $data = json_decode($resp['body'], true);
    if (isset($data['error'])) return ['error' => $data['error']['message'] ?? json_encode($data['error'])];

    $text = $data['choices'][0]['message']['content'] ?? '';
    $usage = $data['usage'] ?? [];
    $inputTokens = $usage['prompt_tokens'] ?? 0;
    $outputTokens = $usage['completion_tokens'] ?? 0;
    $cachedTokens = $usage['prompt_tokens_details']['cached_tokens'] ?? 0;

    return buildResult($provider, $text, $inputTokens, $outputTokens, $cachedTokens);
}

function callClaude(string $endpoint, string $apiKey, string $model, string $sys, string $user, array $provider): array {
    $body = json_encode([
        'model' => $model,
        'system' => $sys,
        'messages' => [
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => 4096,
        'temperature' => 0.8,
    ], JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ];

    $resp = curlPost($endpoint, $body, $headers);
    if (isset($resp['error'])) return $resp;

    $data = json_decode($resp['body'], true);
    if (isset($data['error'])) return ['error' => $data['error']['message'] ?? json_encode($data['error'])];

    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $text .= $block['text'];
    }
    $usage = $data['usage'] ?? [];
    $inputTokens = $usage['input_tokens'] ?? 0;
    $outputTokens = $usage['output_tokens'] ?? 0;
    $cachedTokens = $usage['cache_read_input_tokens'] ?? 0;

    return buildResult($provider, $text, $inputTokens, $outputTokens, $cachedTokens);
}

function callGemini(string $endpoint, string $apiKey, string $model, string $sys, string $user, array $provider): array {
    $url = $endpoint . $model . ':generateContent?key=' . $apiKey;

    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $sys]]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $user]]],
        ],
        'generationConfig' => [
            'maxOutputTokens' => 4096,
            'temperature' => 0.8,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $headers = ['Content-Type: application/json'];

    $resp = curlPost($url, $body, $headers);
    if (isset($resp['error'])) return $resp;

    $data = json_decode($resp['body'], true);
    if (isset($data['error'])) return ['error' => $data['error']['message'] ?? json_encode($data['error'])];

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $usage = $data['usageMetadata'] ?? [];
    $inputTokens = $usage['promptTokenCount'] ?? 0;
    $outputTokens = $usage['candidatesTokenCount'] ?? 0;
    $cachedTokens = $usage['cachedContentTokenCount'] ?? 0;

    return buildResult($provider, $text, $inputTokens, $outputTokens, $cachedTokens);
}

function buildResult(array $provider, string $text, int $inputTokens, int $outputTokens, int $cachedTokens): array {
    $costIn = ($inputTokens / 1000000) * (float)$provider['cost_input'];
    $costOut = ($outputTokens / 1000000) * (float)$provider['cost_output'];

    return [
        'text' => $text,
        'provider' => $provider['display_name'] ?: $provider['name'],
        'model' => $provider['model'],
        'input_tokens' => $inputTokens,
        'output_tokens' => $outputTokens,
        'cached_tokens' => $cachedTokens,
        'cost_input' => round($costIn, 6),
        'cost_output' => round($costOut, 6),
        'cost_total' => round($costIn + $costOut, 6),
    ];
}

function curlPost(string $url, string $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['error' => 'cURL: ' . $err];
    if ($httpCode >= 400) {
        $decoded = json_decode($response, true);
        $msg = $decoded['error']['message'] ?? "HTTP {$httpCode}";
        return ['error' => $msg];
    }

    return ['body' => $response];
}
