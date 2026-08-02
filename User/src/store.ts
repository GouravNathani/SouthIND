import { configureStore } from "@reduxjs/toolkit";
import { setupListeners } from "@reduxjs/toolkit/query";
import { sindApi } from "@/services/api";

export const store = configureStore({
  reducer: {
    [sindApi.reducerPath]: sindApi.reducer,
  },
  middleware: (getDefaultMiddleware) => getDefaultMiddleware().concat(sindApi.middleware),
});

setupListeners(store.dispatch);

export type RootState = ReturnType<typeof store.getState>;
export type AppDispatch = typeof store.dispatch;
