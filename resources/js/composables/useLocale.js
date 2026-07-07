import { ref, computed } from 'vue';
import en from '../i18n/en.js';
import vi from '../i18n/vi.js';

const locales = { en, vi };
const currentLocale = ref('en');

// Hydrate from localStorage only in browser context
if (typeof window !== 'undefined') {
  try {
    const saved = localStorage.getItem('catte_locale');
    if (saved && locales[saved]) {
      currentLocale.value = saved;
    }
  } catch (e) {
    // localStorage unavailable (private browsing, etc.)
  }
}

export function useLocale() {
  const locale = computed(() => currentLocale.value);
  const t = computed(() => locales[currentLocale.value] || locales.en);

  function setLocale(lang) {
    if (locales[lang]) {
      currentLocale.value = lang;
      if (typeof window !== 'undefined') {
        try { localStorage.setItem('catte_locale', lang); } catch (e) {}
      }
    }
  }

  function toggleLocale() {
    setLocale(currentLocale.value === 'en' ? 'vi' : 'en');
  }

  /**
   * Get a nested translation string by dot-path.
   * Supports simple {name} interpolation.
   * Example: msg('game.turnStatus.playerTurn', { name: 'Leo' })
   */
  function msg(path, params = {}) {
    const keys = path.split('.');
    let value = locales[currentLocale.value] || locales.en;
    for (const key of keys) {
      value = value?.[key];
      if (value === undefined) {
        // Fallback to English
        value = locales.en;
        for (const k of keys) {
          value = value?.[k];
          if (value === undefined) return path; // key not found
        }
        break;
      }
    }
    if (typeof value !== 'string') return path;
    // Simple interpolation
    return value.replace(/\{(\w+)\}/g, (_, key) => params[key] ?? `{${key}}`);
  }

  return { locale, t, setLocale, toggleLocale, msg };
}
