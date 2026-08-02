import { HashRouter, Navigate, Route, Routes } from "react-router-dom";
import LoginPage from "@/pages/LoginPage";
import DashboardPage from "@/pages/DashboardPage";
import DepositPage from "@/pages/deposit/DepositPage";
import DepositUpdatePage from "@/pages/deposit/DepositUpdatePage";
import WithdrawPage from "@/pages/withdraw/WithdrawPage";
import WithdrawUpdatePage from "@/pages/withdraw/WithdrawUpdatePage";
import UsersPage from "@/pages/users/UsersPage";
import UserDetailPage from "@/pages/users/UserDetailPage";
import AccountsPage from "@/pages/accounts/AccountsPage";
import NewAccountPage from "@/pages/accounts/NewAccountPage";
import AccountDetailPage from "@/pages/accounts/AccountDetailPage";

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

        <Route path="/users" element={<UsersPage />} />
        <Route path="/users/:id" element={<UserDetailPage />} />

        {/* /new must precede /:id or "new" is parsed as an account id. */}
        <Route path="/accounts" element={<AccountsPage />} />
        <Route path="/accounts/new" element={<NewAccountPage />} />
        <Route path="/accounts/:id" element={<AccountDetailPage />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </HashRouter>
  );
}
