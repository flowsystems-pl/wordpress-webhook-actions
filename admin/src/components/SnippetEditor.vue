<script setup>
import { ref, watch, onMounted, onBeforeUnmount, shallowRef } from 'vue'
import { EditorView, basicSetup } from 'codemirror'
import { php } from '@codemirror/lang-php'
import { EditorState } from '@codemirror/state'
import { oneDark } from '@codemirror/theme-one-dark'
import { useTheme } from '@/composables/useTheme'

const props = defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  readonly: {
    type: Boolean,
    default: false,
  },
  placeholder: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue'])

const editorContainer = ref(null)
const view = shallowRef(null)
const { theme } = useTheme()

let suppressUpdate = false

const buildExtensions = () => {
  const exts = [
    basicSetup,
    php({ plain: true }),
    EditorView.lineWrapping,
    EditorView.updateListener.of((update) => {
      if (update.docChanged && !suppressUpdate) {
        emit('update:modelValue', update.state.doc.toString())
      }
    }),
  ]

  if (theme.value === 'dark') {
    exts.push(oneDark)
  }

  if (props.readonly) {
    exts.push(EditorState.readOnly.of(true))
  }

  return exts
}

const initEditor = () => {
  if (!editorContainer.value) return

  view.value = new EditorView({
    state: EditorState.create({
      doc: props.modelValue ?? '',
      extensions: buildExtensions(),
    }),
    parent: editorContainer.value,
  })
}

// Sync external value changes into the editor without triggering emit
watch(
  () => props.modelValue,
  (newVal) => {
    if (!view.value) return
    const current = view.value.state.doc.toString()
    if (newVal !== current) {
      suppressUpdate = true
      view.value.dispatch({
        changes: { from: 0, to: current.length, insert: newVal ?? '' },
      })
      suppressUpdate = false
    }
  },
)

// Rebuild when theme changes
watch(theme, () => {
  if (!view.value) return
  const doc = view.value.state.doc.toString()
  view.value.destroy()
  view.value = null
  setTimeout(() => {
    view.value = new EditorView({
      state: EditorState.create({
        doc,
        extensions: buildExtensions(),
      }),
      parent: editorContainer.value,
    })
  }, 0)
})

onMounted(initEditor)

onBeforeUnmount(() => {
  view.value?.destroy()
})
</script>

<template>
  <div
    ref="editorContainer"
    class="rounded-md border border-input overflow-hidden text-sm font-mono [&_.cm-editor]:outline-none [&_.cm-editor.cm-focused]:outline-none [&_.cm-scroller]:min-h-[200px] [&_.cm-scroller]:max-h-[420px] [&_.cm-scroller]:overflow-auto"
  />
</template>
