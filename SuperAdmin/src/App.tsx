import { HashRouter, Navigate, Route, Routes } from "react-router-dom";
import { BranchProvider } from "@/components/BranchContext";
import LoginPage from "@/pages/LoginPage";
import DashboardPage from "@/pages/DashboardPage";
import BranchesPage from "@/pages/BranchesPage";
import AdminsPage from "@/pages/AdminsPage";
import GlobalSettingsPage from "@/pages/GlobalSettingsPage";

export default function App() {
  return (
    <HashRouter>
      <BranchProvider>
        <Routes>
          <Route path="/" element={<LoginPage />} />
          <Route path="/dashboard" element={<DashboardPage />} />

          <Route path="/branches" element={<BranchesPage />} />
          <Route path="/admins" element={<AdminsPage />} />
          <Route path="/settings" element={<GlobalSettingsPage />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </BranchProvider>
    </HashRouter>
  );
}
