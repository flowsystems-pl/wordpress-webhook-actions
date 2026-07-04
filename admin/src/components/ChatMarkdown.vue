<script setup>
import { computed } from 'vue';
import { Copy, Check } from 'lucide-vue-next';
import { useCopyToClipboard } from '@/composables/useCopyToClipboard';
import { __ } from '@/i18n';

// Renders an assistant chat message: fenced ```code``` blocks become styled,
// copyable blocks; the surrounding prose gets light inline formatting (bold,
// inline code) with line breaks preserved. Deliberately tiny — no markdown
// dependency — and XSS-safe (prose is HTML-escaped before any formatting).

const props = defineProps({ text: { type: String, default: '' } });
const { copiedKey, copy } = useCopyToClipboard();

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

const copyKey = (k) => 'code-' + k;
</script>

<template>
  <div class="space-y-2 min-w-0 max-w-full">
    <template v-for="b in blocks" :key="b.key">
      <!-- Prose -->
      <div v-if="b.type === 'text' && b.content.trim()" class="leading-relaxed" v-html="inlineHtml(b.content)" />

      <!-- Code block -->
      <div v-else-if="b.type === 'code'" class="rounded-md border border-black/20 dark:border-white/10 overflow-hidden">
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
