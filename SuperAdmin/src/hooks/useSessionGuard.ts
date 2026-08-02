import { useEffect } from "react";
import { useNavigate } from "react-router-dom";

import { isUnauthorizedError } from "../utils/errors";

const TOKEN_KEY = "sind-super-token";

export const useSessionGuard = (...errors: unknown[]) => {
  const navigate = useNavigate();

  useEffect(() => {
    if (errors.some((error) => isUnauthorizedError(error))) {
      sessionStorage.removeItem(TOKEN_KEY);
      navigate("/");
    }
  }, [navigate, ...errors]);
};
