import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import AppShell from "@/components/AppShell";
import Card from "@/components/ui/Card";
import Button from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/Feedback";

export default function NotFoundPage() {
  const navigate = useNavigate();
  const { t } = useTranslation();

  return (
    <AppShell title={t("common.notFound")}>
      <Card>
        <EmptyState
          title={t("common.notFound")}
          body={t("common.notFoundBody")}
          action={<Button onClick={() => navigate("/dashboard")}>{t("dashboard.title")}</Button>}
        />
      </Card>
    </AppShell>
  );
}
