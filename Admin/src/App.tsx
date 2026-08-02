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
import SettingPage from "@/pages/setting/SettingPage";
import BannerPage from "@/pages/banner/BannerPage";
import BonusCodePage from "@/pages/bonus/BonusCodePage";
import WinnerStreakPage from "@/pages/winner/WinnerStreakPage";
import SupportPage from "@/pages/support/SupportPage";
import WhatsAppInboxPage from "@/pages/whatsapp/WhatsAppInboxPage";
import ReferralPage from "@/pages/referral/ReferralPage";

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

        <Route path="/banner" element={<BannerPage />} />
        <Route path="/bonus" element={<BonusCodePage />} />
        <Route path="/winner-streak" element={<WinnerStreakPage />} />
        <Route path="/support" element={<SupportPage />} />
        <Route path="/whatsapp" element={<WhatsAppInboxPage />} />
        <Route path="/referral" element={<ReferralPage />} />
        <Route path="/setting" element={<SettingPage />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </HashRouter>
  );
}
