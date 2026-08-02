import { HashRouter, Navigate, Route, Routes } from "react-router-dom";
import { BranchProvider } from "@/components/BranchContext";
import LoginPage from "@/pages/LoginPage";
import DashboardPage from "@/pages/DashboardPage";

export default function App() {
  return (
    <HashRouter>
      <BranchProvider>
        <Routes>
          <Route path="/" element={<LoginPage />} />
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </BranchProvider>
    </HashRouter>
  );
}
