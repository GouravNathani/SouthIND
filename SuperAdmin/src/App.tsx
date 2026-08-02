import { HashRouter, Navigate, Route, Routes } from "react-router-dom";
import { BranchProvider } from "@/components/BranchContext";
import LoginPage from "@/pages/LoginPage";
import DashboardPage from "@/pages/DashboardPage";
import BranchesPage from "@/pages/BranchesPage";
import AdminsPage from "@/pages/AdminsPage";
import GlobalSettingsPage from "@/pages/GlobalSettingsPage";
import DepositPage from "@/pages/deposit/DepositPage";
import DepositUpdatePage from "@/pages/deposit/DepositUpdatePage";
import WithdrawPage from "@/pages/withdraw/WithdrawPage";
import WithdrawUpdatePage from "@/pages/withdraw/WithdrawUpdatePage";
import UsersPage from "@/pages/users/UsersPage";
import AccountsPage from "@/pages/accounts/AccountsPage";
import NewAccountPage from "@/pages/accounts/NewAccountPage";
import AccountDetailPage from "@/pages/accounts/AccountDetailPage";
import WalletPage from "@/pages/WalletPage";
import PayoutPage from "@/pages/PayoutPage";
import WhatsAppAccountsPage from "@/pages/WhatsAppAccountsPage";
import BannerPage from "@/pages/banner/BannerPage";
import BonusCodePage from "@/pages/bonus/BonusCodePage";
import WinnerStreakPage from "@/pages/winner/WinnerStreakPage";
import SupportPage from "@/pages/support/SupportPage";
import ReferralPage from "@/pages/referral/ReferralPage";

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

          <Route path="/deposit" element={<DepositPage />} />
          <Route path="/deposit/:id" element={<DepositUpdatePage />} />
          <Route path="/withdraw" element={<WithdrawPage />} />
          <Route path="/withdraw/:id" element={<WithdrawUpdatePage />} />

          <Route path="/users" element={<UsersPage />} />

          <Route path="/accounts" element={<AccountsPage />} />
          <Route path="/accounts/new" element={<NewAccountPage />} />
          <Route path="/accounts/:id" element={<AccountDetailPage />} />

          <Route path="/wallet" element={<WalletPage />} />
          <Route path="/payout" element={<PayoutPage />} />
          <Route path="/whatsapp" element={<WhatsAppAccountsPage />} />

          <Route path="/banner" element={<BannerPage />} />
          <Route path="/bonus" element={<BonusCodePage />} />
          <Route path="/winner-streak" element={<WinnerStreakPage />} />
          <Route path="/support" element={<SupportPage />} />
          <Route path="/agents" element={<ReferralPage />} />
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </BranchProvider>
    </HashRouter>
  );
}
