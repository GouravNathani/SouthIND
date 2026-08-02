import i18n from "i18next";
import { initReactI18next } from "react-i18next";
import LanguageDetector from "i18next-browser-languagedetector";

import en from "./locales/en.json";
import hi from "./locales/hi.json";
import te from "./locales/te.json";
import bn from "./locales/bn.json";
import kn from "./locales/kn.json";
import ta from "./locales/ta.json";
import ml from "./locales/ml.json";
import mr from "./locales/mr.json";
import or from "./locales/or.json";

export const SUPPORTED_LANGUAGES = [
  { code: "en", label: "English", native: "English" },
  { code: "hi", label: "Hindi", native: "हिन्दी" },
  { code: "ta", label: "Tamil", native: "தமிழ்" },
  { code: "te", label: "Telugu", native: "తెలుగు" },
  { code: "ml", label: "Malayalam", native: "മലയാളം" },
  { code: "kn", label: "Kannada", native: "ಕನ್ನಡ" },
  { code: "mr", label: "Marathi", native: "मराठी" },
  { code: "or", label: "Odia", native: "ଓଡ଼ିଆ" },
  { code: "bn", label: "Bengali", native: "বাংলা" },
] as const;

export const LANGUAGE_STORAGE_KEY = "sind_lang";

i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      en: { translation: en },
      hi: { translation: hi },
      ta: { translation: ta },
      te: { translation: te },
      ml: { translation: ml },
      kn: { translation: kn },
      mr: { translation: mr },
      or: { translation: or },
      bn: { translation: bn },
    },
    fallbackLng: "en",
    supportedLngs: SUPPORTED_LANGUAGES.map((l) => l.code),
    interpolation: { escapeValue: false },
    detection: {
      order: ["localStorage", "navigator"],
      lookupLocalStorage: LANGUAGE_STORAGE_KEY,
      caches: ["localStorage"],
    },
  });

export default i18n;
