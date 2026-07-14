<script setup>
import { computed } from 'vue';
import { Copy, Check } from 'lucide-vue-next';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { __ } from '@/i18n';

// Renders an assistant chat message: fenced ```code``` blocks become styled,
// copyable blocks; the surrounding prose gets light inline formatting (bold,
// inline code) with line breaks preserved. Deliberately tiny — no markdown
// dependency — and XSS-safe (prose is HTML-escaped before any formatting).
//
// When `animate` is true (a freshly-arrived reply), the fully-rendered content
// is revealed Claude-style: each prose word — and each code block as a unit —
// fades and rises in on a short left-to-right stagger. The markdown is parsed
// once up front, so nothing reflows and formatting never flickers; the stagger
// is capped so long replies still finish quickly, and it is disabled entirely
// under prefers-reduced-motion.

const props = defineProps({
  text: { type: String, default: '' },
  animate: { type: Boolean, default: false },
});
const { copiedKey, copy } = useCopyToClipboard();

const REVEAL_STEP_MS = 26;   // gap between consecutive words
// Ceiling on the total stagger, sized in TIME rather than items: words past it
// share the last delay. The old 70-item cap meant any reply longer than a
// couple of sentences dumped its whole tail after ~1.8s, killing the
// streaming feel mid-message; ~10s covers a ~380-word reply word-by-word.
const REVEAL_MAX_MS = 10000;
const REVEAL_CAP = Math.floor(REVEAL_MAX_MS / REVEAL_STEP_MS);

// Split the message into alternating text / fenced-code segments.
const blocks = computed(() => {
  const src = props.text || '';
  const re = /```(\w*)[ \t]*\r?\n?([\s\S]*?)```/g;
  const out = [];
  let last = 0;
  let key = 0;
  let m;
  while ((m = re.exec(src)) !== null) {
    if (m.index > last) out.push({ type: 'text', key: key++, content: src.slice(last, m.index) });
    out.push({ type: 'code', key: key++, lang: m[1] || '', code: m[2].replace(/\s+$/, '') });
    last = re.lastIndex;
  }
  if (last < src.length) out.push({ type: 'text', key: key++, content: src.slice(last) });
  return out;
});

function escapeHtml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Escape first, then apply a minimal, safe subset of inline markdown.
function inlineHtml(text) {
  let h = escapeHtml(text.replace(/^\n+|\n+$/g, ''));
  h = h.replace(/`([^`]+)`/g, '<code class="rounded bg-black/10 dark:bg-white/15 px-1 py-0.5 text-[0.85em] font-mono">$1</code>');
  h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  h = h.replace(/\n/g, '<br>');
  return h;
}

// Wrap each visible word (text between tags) in a reveal span with a staggered
// delay, leaving HTML tags and whitespace untouched so formatting is preserved.
function wrapWords(html, next) {
  return html.replace(/(<[^>]+>)|([^<]+)/g, (_m, tag, txt) => {
    if (tag) return tag;
    return txt.replace(/(\s+)|(\S+)/g, (_mm, sp, word) =>
      sp != null ? sp : `<span class="fswa-rw" style="animation-delay:${next()}ms">${word}</span>`
    );
  });
}

// Rendered segments with reveal metadata. A single running counter across all
// segments keeps the stagger continuous (prose words then code blocks in order).
const rendered = computed(() => {
  let idx = 0;
  const next = () => Math.min(idx++, REVEAL_CAP) * REVEAL_STEP_MS;
  return blocks.value.map((b) => {
    if (b.type === 'text') {
      const html = inlineHtml(b.content);
      return { ...b, html: props.animate ? wrapWords(html, next) : html };
    }
    return { ...b, delayMs: props.animate ? next() : 0 };
  });
});

const copyKey = (k) => 'code-' + k;
</script>

<template>
  <div class="space-y-2 min-w-0 max-w-full">
    <template v-for="b in rendered" :key="b.key">
      <!-- Prose -->
      <div v-if="b.type === 'text' && b.content.trim()" class="leading-relaxed" v-html="b.html" />

      <!-- Code block -->
      <div v-else-if="b.type === 'code'"
        :class="['rounded-md border border-black/20 dark:border-white/10 overflow-hidden', animate ? 'fswa-rb' : '']"
        :style="animate ? { animationDelay: b.delayMs + 'ms' } : null">
        <div class="flex items-center justify-between gap-2 px-3 py-1.5 bg-zinc-800 dark:bg-white/5 border-b border-white/10">
          <span class="text-[11px] font-mono uppercase tracking-wide text-zinc-400">{{ b.lang || 'code' }}</span>
          <button type="button" @click="copy(b.code, copyKey(b.key))"
            class="flex items-center gap-1 text-[11px] text-zinc-300 hover:text-white transition-colors shrink-0">
            <Check v-if="copiedKey === copyKey(b.key)" class="w-3.5 h-3.5 text-emerald-400" />
            <Copy v-else class="w-3.5 h-3.5" />
            {{ copiedKey === copyKey(b.key) ? __('Copied') : __('Copy') }}
          </button>
        </div>
        <pre class="overflow-x-auto p-3 text-xs leading-relaxed bg-zinc-900"><code class="font-mono text-zinc-100">{{ b.code }}</code></pre>
      </div>
    </template>
  </div>
</template>

<!-- Unscoped on purpose: the reveal spans are injected via v-html, so scoped
     rules (and scoped @keyframes name-rewriting) wouldn't reach them. Classes
     are fswa- prefixed to avoid any global collision. -->
<style>
.fswa-rw {
  display: inline-block;
  opacity: 0;
  animation: fswa-reveal 0.34s ease-out both;
}
.fswa-rb {
  opacity: 0;
  animation: fswa-reveal 0.34s ease-out both;
}
@keyframes fswa-reveal {
  from { opacity: 0; transform: translateY(0.28em); filter: blur(2px); }
  to   { opacity: 1; transform: none; filter: blur(0); }
}
@media (prefers-reduced-motion: reduce) {
  .fswa-rw,
  .fswa-rb {
    animation: none;
    opacity: 1;
  }
}
</style>
