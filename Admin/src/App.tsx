import { HashRouter, Navigate, Route, Routes } from "react-router-dom";
import LoginPage from "@/pages/LoginPage";
import DashboardPage from "@/pages/DashboardPage";
import DepositPage from "@/pages/deposit/DepositPage";
import DepositUpdatePage from "@/pages/deposit/DepositUpdatePage";
import WithdrawPage from "@/pages/withdraw/WithdrawPage";
import WithdrawUpdatePage from "@/pages/withdraw/WithdrawUpdatePage";

export default function App() {
  return (
    <HashRouter>
      <Routes>
        <Route path="/" element={<LoginPage />} />
        <Route path="/dashboard" element={<DashboardPage />} />

        <Route path="/deposit" element={<DepositPage />} />
        <Route path="/deposit/:id" element={<DepositUpdatePage />} />

        <Route path="/withdraw" element={<WithdrawPage />} />
        <Route path="/withdraw/:id" element={<WithdrawUpdatePage />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </HashRouter>
  );
}
