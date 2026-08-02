import { HashRouter, Navigate, Route, Routes } from "react-router-dom";
import AuthStatusGate from "@/components/AuthStatusGate";
import LoginPage from "@/pages/login/LoginPage";
import DashboardPage from "@/pages/dashboard/DashboardPage";
import SelectDepositAccountPage from "@/pages/deposit/SelectDepositAccountPage";
import DepositDetailsPage from "@/pages/deposit/DepositDetailsPage";
import AddWithdrawalPage from "@/pages/withdrawal/AddWithdrawalPage";
import HistoryPage from "@/pages/history/HistoryPage";
import ChatPage from "@/pages/chat/ChatPage";
import WinnersPage from "@/pages/winners/WinnersPage";
import AccountPage from "@/pages/account/AccountPage";
import NotFoundPage from "@/pages/NotFoundPage";

export default function App() {
  return (
    <HashRouter>
      <AuthStatusGate>
        <Routes>
          <Route path="/" element={<LoginPage />} />
          <Route path="/login" element={<Navigate to="/" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />

          {/* The tab lands straight on the picker; BC's redirect-only screens are
              kept alive as aliases so old links and bookmarks still resolve. */}
          <Route path="/deposit" element={<SelectDepositAccountPage />} />
          <Route path="/deposit/add" element={<Navigate to="/deposit" replace />} />
          <Route path="/deposit/add/details" element={<DepositDetailsPage />} />

          <Route path="/withdrawal" element={<AddWithdrawalPage />} />
          <Route path="/withdrawal/add" element={<Navigate to="/withdrawal" replace />} />

          {/* Bonus codes are applied on the deposit form, so /bonus has no page. */}
          <Route path="/bonus" element={<Navigate to="/dashboard" replace />} />

          <Route path="/history" element={<HistoryPage />} />
          <Route path="/chat" element={<ChatPage />} />
          <Route path="/winners" element={<WinnersPage />} />
          <Route path="/account" element={<AccountPage />} />

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </AuthStatusGate>
    </HashRouter>
  );
}
