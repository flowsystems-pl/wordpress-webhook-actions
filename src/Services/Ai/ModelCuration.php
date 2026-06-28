<?php

namespace FlowSystems\WebhookActions\Services\Ai;

defined('ABSPATH') || exit;

/**
 * Shared, id/name-based curation rules for AI models, applied identically to the
 * WordPress AI Client registry path and the bring-your-own-key path so the model
 * picker behaves the same regardless of where the credentials come from.
 *
 * The rules are deliberately pattern-based (not a hardcoded id list) so they keep
 * working as providers add and retire models.
 */
final class ModelCuration {
  /**
   * Name/id fragments for purpose-built models that cannot drive the agent
   * (image, audio, video, embedding, etc.). Some of these still report a
   * text_generation capability, so a name/id check is required in addition to
   * any capability filtering the caller does.
   */
  private const EXCLUDE_PATTERN = '/(image|banana|tts|audio|speech|voice|whisper|realtime|transcribe|robotics|lyria|music|veo|imagen|sora|video|embed|moderation|guard|dall)/i';

  /**
   * Fragments that demote an otherwise-suitable model out of the curated
   * "Recommended" view: pinned snapshots, legacy families, weak tiers, and niche
   * variants. The full list stays reachable behind the "show all" toggle.
   */
  private const NON_RECOMMENDED_PATTERN = '#('
    . '\d{4}-\d{2}-\d{2}'          // dated snapshot pin, e.g. -2026-03-05
    . '|-\d{3,4}$|-16k'            // legacy numeric snapshot, e.g. -0613, -16k
    . '|nano'                      // too weak for reliable structured plans
    . '|codex|search|computer-use|deep-research|antigravity'  // niche/specialised
    . '|gpt-3|gpt-4($|[-.]0|-turbo|-32k)|o1'                  // legacy OpenAI
    . '|gemini-1|gemini-2\.0|gemma'                           // legacy Google
    . ')#i';

  /**
   * Whether a model is a general-purpose text model the end user could sensibly
   * pick for building integrations (vs an image/audio/embedding model).
   */
  public static function isSuitable(string $id, string $name = ''): bool {
    return !preg_match(self::EXCLUDE_PATTERN, $id)
      && !preg_match(self::EXCLUDE_PATTERN, $name);
  }

  /**
   * Whether a (suitable) model belongs in the curated default view.
   */
  public static function isRecommended(string $id, string $name = ''): bool {
    return !preg_match(self::NON_RECOMMENDED_PATTERN, $id)
      && !preg_match(self::NON_RECOMMENDED_PATTERN, $name);
  }

  /**
   * Shape a raw {id,name} into a picker entry with the recommended flag, or null
   * when the model is unsuitable. Convenience for catalog builders.
   *
   * @return array{id:string,name:string,recommended:bool}|null
   */
  public static function entry(string $id, string $name = ''): ?array {
    $id = trim($id);
    if ($id === '') {
      return null;
    }
    $name = $name !== '' ? $name : $id;
    if (!self::isSuitable($id, $name)) {
      return null;
    }
    return [
      'id'          => $id,
      'name'        => $name,
      'recommended' => self::isRecommended($id, $name),
    ];
  }
}
