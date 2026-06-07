<?php
// ═══════════════════════════════════════════════
//  Forge 挿絵プロンプト生成ヘルパー v2
//  保存済みキャラプロンプト対応
// ═══════════════════════════════════════════════

function generateIllustPrompt(PDO $pdo, int $providerId, string $sceneText, array $characters = [], string $extraDirections = ''): array {
    require_once __DIR__ . '/forge_api.php';

    $systemPrompt = "あなたは画像生成AI向けのプロンプト作成の専門家です。
与えられた小説のシーンを、画像生成AI（Stable Diffusion, Midjourney, DALL-E等）で使えるプロンプトに変換してください。

## 出力ルール
- 英語で出力すること
- 以下の順番で構成すること:
  1. シーンの主要な描写（人物のポーズ、表情、アクション）
  2. 人物の外見描写（髪型、目の色、服装、体型）
  3. 背景・環境描写
  4. 雰囲気・照明・色調
  5. アートスタイル指定
- 1つのプロンプトとして、カンマ区切りで出力
- ネガティブプロンプトも別途出力
- 日本語の補足説明を最後に簡潔に付ける

## 出力フォーマット
```
PROMPT:
(ここにプロンプト)

NEGATIVE:
(ここにネガティブプロンプト)

補足:
(シーンの簡潔な日本語説明)
```";

    // キャラクター情報を追加（保存済みプロンプトを優先使用）
    if ($characters) {
        $hasAnyPrompt = false;
        foreach ($characters as $c) {
            if (!empty($c['illust_prompt'])) { $hasAnyPrompt = true; break; }
        }

        if ($hasAnyPrompt) {
            $systemPrompt .= "\n\n## 登場キャラクター（画像生成用プロンプト）\n";
            $systemPrompt .= "以下は各キャラクターの外見を画像生成AI向けに記述したものです。これをシーンプロンプトに統合してください。\n\n";
            foreach ($characters as $c) {
                $systemPrompt .= "### {$c['name']}\n";
                if (!empty($c['illust_prompt'])) {
                    // 💎 保存済みプロンプトを使用（トークン節約）
                    $systemPrompt .= $c['illust_prompt'] . "\n\n";
                } else {
                    // フォールバック: 生データを使用
                    $systemPrompt .= buildCharDescription($c) . "\n\n";
                }
            }
        } else {
            $systemPrompt .= "\n\n## 登場キャラクター情報\n";
            foreach ($characters as $c) {
                $systemPrompt .= "### {$c['name']}\n" . buildCharDescription($c) . "\n\n";
            }
        }
    }

    $userPrompt = "以下のシーンを画像生成AI向けプロンプトに変換してください:\n\n" . $sceneText;
    if ($extraDirections) {
        $userPrompt .= "\n\n## 追加指示:\n" . $extraDirections;
    }

    return forgeCallAPI($pdo, $providerId, $systemPrompt, $userPrompt);
}

function buildCharDescription(array $c): string {
    $desc = '';
    if (!empty($c['gender'])) $desc .= "性別: {$c['gender']}\n";
    if (!empty($c['hairstyle'])) $desc .= "髪型: {$c['hairstyle']}\n";
    if (!empty($c['eye_color'])) $desc .= "目の色: {$c['eye_color']}\n";
    if (!empty($c['clothing'])) $desc .= "服装: {$c['clothing']}\n";
    if (!empty($c['body_type'])) $desc .= "体型: {$c['body_type']}\n";
    if (!empty($c['height'])) $desc .= "身長: {$c['height']}\n";
    if (!empty($c['style'])) $desc .= "系統: {$c['style']}\n";
    if (!empty($c['features'])) $desc .= "特徴: {$c['features']}\n";
    return $desc;
}
