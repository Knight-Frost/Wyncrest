import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router';
import { AppShell } from '@/components/layout/AppShell';
import { RequireAuth, RequireRole, RequireAdminCapability, RedirectIfAuthed } from '@/components/routing/guards';
import { useAuth } from '@/context/auth';

import { Login } from '@/pages/auth/Login';
import { AdminLogin } from '@/pages/auth/AdminLogin';
import { Register } from '@/pages/auth/Register';
import { AcceptInvite } from '@/pages/auth/AcceptInvite';
import { ForgotPassword } from '@/pages/auth/ForgotPassword';
import { ResetPassword } from '@/pages/auth/ResetPassword';
import { VerifyEmail } from '@/pages/auth/VerifyEmail';
import { NotFound } from '@/pages/NotFound';
import { DashboardRouter } from '@/pages/DashboardRouter';

/* ---- Lazy page imports --------------------------------------------------- */
// Landing is lazy so its animation libs (gsap, lenis) chunk separately and only
// load on "/", never for visitors who land straight on /login or /register.
const Landing           = lazy(() => import('@/pages/Landing').then((m) => ({ default: m.Landing })));

// Tenant
const BrowseListings    = lazy(() => import('@/pages/tenant/BrowseListings').then((m) => ({ default: m.BrowseListings })));
const ListingDetail     = lazy(() => import('@/pages/ListingDetail').then((m) => ({ default: m.ListingDetail })));
const SavedListings     = lazy(() => import('@/pages/tenant/SavedListings').then((m) => ({ default: m.SavedListings })));
const ApplicationsPage  = lazy(() => import('@/pages/tenant/ApplicationsPage').then((m) => ({ default: m.ApplicationsPage })));
const ApplicationDetail = lazy(() => import('@/pages/tenant/ApplicationDetail').then((m) => ({ default: m.ApplicationDetail })));
const ApplicationForm   = lazy(() => import('@/pages/tenant/ApplicationForm').then((m) => ({ default: m.ApplicationForm })));
const PaymentsPage      = lazy(() => import('@/pages/tenant/PaymentsPage').then((m) => ({ default: m.PaymentsPage })));
const MaintenancePage   = lazy(() => import('@/pages/tenant/MaintenancePage').then((m) => ({ default: m.MaintenancePage })));
const NewMaintenanceRequestPage = lazy(() => import('@/pages/tenant/NewMaintenanceRequestPage').then((m) => ({ default: m.NewMaintenanceRequestPage })));
const ComparePage       = lazy(() => import('@/pages/tenant/ComparePage').then((m) => ({ default: m.ComparePage })));
const VerificationCenter = lazy(() => import('@/pages/tenant/VerificationCenter').then((m) => ({ default: m.VerificationCenter })));
const MyReviews         = lazy(() => import('@/pages/tenant/MyReviews').then((m) => ({ default: m.MyReviews })));

// Landlord
const Properties        = lazy(() => import('@/pages/landlord/Properties').then((m) => ({ default: m.Properties })));
const AddPropertyPage   = lazy(() => import('@/pages/landlord/AddPropertyPage').then((m) => ({ default: m.AddPropertyPage })));
const PropertyDetail    = lazy(() => import('@/pages/landlord/PropertyDetail').then((m) => ({ default: m.PropertyDetail })));
const LandlordListings  = lazy(() => import('@/pages/landlord/LandlordListings').then((m) => ({ default: m.LandlordListings })));
const LandlordListingDetail = lazy(() => import('@/pages/landlord/ListingDetail').then((m) => ({ default: m.ListingDetail })));
const CreateListing     = lazy(() => import('@/pages/landlord/CreateListing').then((m) => ({ default: m.CreateListing })));
const CreateListingForUnit = lazy(() => import('@/pages/landlord/CreateListingForUnit').then((m) => ({ default: m.CreateListingForUnit })));
const Applicants        = lazy(() => import('@/pages/landlord/Applicants').then((m) => ({ default: m.Applicants })));
const ApplicantDetail   = lazy(() => import('@/pages/landlord/ApplicantDetail').then((m) => ({ default: m.ApplicantDetail })));
const ApplicantsCompare = lazy(() => import('@/pages/landlord/ApplicantsCompare').then((m) => ({ default: m.ApplicantsCompare })));
const TenantManagement  = lazy(() => import('@/pages/landlord/TenantManagement').then((m) => ({ default: m.TenantManagement })));
const TenantDetail      = lazy(() => import('@/pages/landlord/TenantDetail').then((m) => ({ default: m.TenantDetail })));
const LandlordMaintenance = lazy(() => import('@/pages/landlord/LandlordMaintenance').then((m) => ({ default: m.LandlordMaintenance })));
const LandlordMaintenanceDetail = lazy(() => import('@/pages/landlord/LandlordMaintenanceDetail').then((m) => ({ default: m.LandlordMaintenanceDetail })));
const TenantMaintenanceDetail = lazy(() => import('@/pages/tenant/TenantMaintenanceDetail').then((m) => ({ default: m.TenantMaintenanceDetail })));
const LandlordLedger    = lazy(() => import('@/pages/landlord/LandlordLedger').then((m) => ({ default: m.LandlordLedger })));
const LedgerTransaction = lazy(() => import('@/pages/landlord/LedgerTransaction').then((m) => ({ default: m.LedgerTransaction })));
const LedgerStatement   = lazy(() => import('@/pages/landlord/LedgerStatement').then((m) => ({ default: m.LedgerStatement })));
const LedgerPropertyStatement = lazy(() => import('@/pages/landlord/LedgerPropertyStatement').then((m) => ({ default: m.LedgerPropertyStatement })));
const LandlordAnalytics    = lazy(() => import('@/pages/landlord/LandlordAnalytics').then((m) => ({ default: m.LandlordAnalytics })));
const LandlordVerification = lazy(() => import('@/pages/landlord/LandlordVerification').then((m) => ({ default: m.LandlordVerification })));
const LandlordReviews      = lazy(() => import('@/pages/landlord/LandlordReviews').then((m) => ({ default: m.LandlordReviews })));

// Admin
const ListingReview           = lazy(() => import('@/pages/admin/ListingReview').then((m) => ({ default: m.ListingReview })));
const ListingReviewDetail     = lazy(() => import('@/pages/admin/ListingReviewDetail').then((m) => ({ default: m.ListingReviewDetail })));
const ListingPreview          = lazy(() => import('@/pages/admin/ListingPreview').then((m) => ({ default: m.ListingPreview })));
const AuditLogs               = lazy(() => import('@/pages/admin/AuditLogs').then((m) => ({ default: m.AuditLogs })));
const AuditLogDetail          = lazy(() => import('@/pages/admin/AuditLogDetail').then((m) => ({ default: m.AuditLogDetail })));
const UsersPage               = lazy(() => import('@/pages/admin/UsersPage').then((m) => ({ default: m.UsersPage })));
const AdminMaintenanceQueue    = lazy(() => import('@/pages/admin/AdminMaintenanceQueue').then((m) => ({ default: m.AdminMaintenanceQueue })));
const ManageAccessPage        = lazy(() => import('@/pages/admin/ManageAccessPage').then((m) => ({ default: m.ManageAccessPage })));
const VerificationsPage       = lazy(() => import('@/pages/admin/VerificationsPage').then((m) => ({ default: m.VerificationsPage })));
const PlatformAnalytics       = lazy(() => import('@/pages/admin/PlatformAnalytics').then((m) => ({ default: m.PlatformAnalytics })));
const AdminAnalytics          = lazy(() => import('@/pages/admin/AdminAnalytics').then((m) => ({ default: m.AdminAnalytics })));
const VerificationDetailPage  = lazy(() => import('@/pages/admin/VerificationDetailPage').then((m) => ({ default: m.VerificationDetailPage })));
const ReviewModeration        = lazy(() => import('@/pages/admin/ReviewModeration').then((m) => ({ default: m.ReviewModeration })));
const AdminLedgerPage         = lazy(() => import('@/pages/admin/AdminLedgerPage').then((m) => ({ default: m.AdminLedgerPage })));
const AdminLedgerCaseFile     = lazy(() => import('@/pages/admin/AdminLedgerCaseFile').then((m) => ({ default: m.AdminLedgerCaseFile })));

// Shared
const ContractsPage     = lazy(() => import('@/pages/shared/ContractsPage').then((m) => ({ default: m.ContractsPage })));
const ContractCreatePage = lazy(() => import('@/pages/shared/ContractCreatePage').then((m) => ({ default: m.ContractCreatePage })));
const ContractDetail    = lazy(() => import('@/pages/shared/ContractDetail').then((m) => ({ default: m.ContractDetail })));
const LedgerPage        = lazy(() => import('@/pages/shared/LedgerPage').then((m) => ({ default: m.LedgerPage })));
const Notifications     = lazy(() => import('@/pages/shared/Notifications').then((m) => ({ default: m.Notifications })));
const ProfilePage       = lazy(() => import('@/pages/shared/ProfilePage').then((m) => ({ default: m.ProfilePage })));
const SettingsPage      = lazy(() => import('@/pages/shared/SettingsPage').then((m) => ({ default: m.SettingsPage })));
const DocumentsPage     = lazy(() => import('@/pages/shared/DocumentsPage').then((m) => ({ default: m.DocumentsPage })));
const MessagesPage      = lazy(() => import('@/pages/shared/MessagesPage').then((m) => ({ default: m.MessagesPage })));

/* ---- Role-aware maintenance view ----------------------------------------
   Tenants raise/track requests; landlords triage/advance them; admins get a
   read-only platform-wide queue (no per-case detail yet — see
   AdminMaintenanceQueue's own docblock). Same route, role-specific page. */
function MaintenanceRouter() {
  const { user } = useAuth();
  if (user?.role === 'admin') {
    return <Lazy><AdminMaintenanceQueue /></Lazy>;
  }
  if (user?.role === 'landlord') {
    return <Lazy><LandlordMaintenance /></Lazy>;
  }
  return <Lazy><MaintenancePage /></Lazy>;
}

/* Role-aware maintenance DETAIL: landlords triage, tenants view their own report. */
function MaintenanceDetailRouter() {
  const { user } = useAuth();
  if (user?.role === 'landlord') {
    return <Lazy><LandlordMaintenanceDetail /></Lazy>;
  }
  return <Lazy><TenantMaintenanceDetail /></Lazy>;
}

/* ---- Role-aware rent ledger ---------------------------------------------
   Landlords get the operational Rent Ledger console, admins get the
   platform-wide Ledger command centre (with a per-entry case file), and
   tenants keep the shared read-only ledger view. The API enforces the real
   owner scoping either way. */
function LedgerRouter() {
  const { user } = useAuth();
  if (user?.role === 'landlord') {
    return <Lazy><LandlordLedger /></Lazy>;
  }
  if (user?.role === 'admin') {
    return <Lazy><AdminLedgerPage /></Lazy>;
  }
  return <Lazy><LedgerPage /></Lazy>;
}

/* Only admins have a per-entry ledger case file today; other roles never
   navigate to /app/ledger/:id, but guard it defensively anyway. */
function LedgerDetailRouter() {
  const { user } = useAuth();
  if (user?.role !== 'admin') {
    return <Navigate to="/app/ledger" replace />;
  }
  return <Lazy><AdminLedgerCaseFile /></Lazy>;
}

/* ---- Suspense wrapper ---------------------------------------------------- */
function Lazy({ children }: { children: React.ReactNode }) {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-[60vh] items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
        </div>
      }
    >
      {children}
    </Suspense>
  );
}

/* ---- App ----------------------------------------------------------------- */

export default function App() {
  return (
    <Routes>
      {/* Public */}
      <Route path="/" element={<Lazy><Landing /></Lazy>} />
      <Route
        path="/login"
        element={
          <RedirectIfAuthed>
            <Login />
          </RedirectIfAuthed>
        }
      />
      {/* Admin console sign-in — isolated cookie-session surface. */}
      <Route
        path="/admin/login"
        element={
          <RedirectIfAuthed>
            <AdminLogin />
          </RedirectIfAuthed>
        }
      />
      <Route
        path="/register"
        element={
          <RedirectIfAuthed>
            <Register />
          </RedirectIfAuthed>
        }
      />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route path="/accept-invite" element={<AcceptInvite />} />
      <Route path="/verify-email" element={<VerifyEmail />} />

      {/* Authenticated app */}
      <Route
        path="/app"
        element={
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        }
      >
        {/* Dashboard — role-switched inline (no lazy; small, always needed) */}
        <Route index element={<DashboardRouter />} />

        {/* Shared / any authenticated role */}
        <Route
          path="notifications"
          element={<Lazy><Notifications /></Lazy>}
        />
        <Route
          path="listing/:id"
          element={<Lazy><ListingDetail /></Lazy>}
        />
        {/* Viewing contracts/ledger is a baseline admin privilege (as well as
            each party's own tenant/landlord scope) — only the mutating
            actions inside the pages require manage_contracts/manage_ledger. */}
        <Route
          path="contracts"
          element={<Lazy><ContractsPage /></Lazy>}
        />
        {/* Landlord-only create page — declared BEFORE contracts/:id so "new"
            isn't captured by the :id param. Guarded here + inside the page. */}
        <Route
          path="contracts/new"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><ContractCreatePage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="contracts/:id"
          element={<Lazy><ContractDetail /></Lazy>}
        />
        <Route
          path="ledger"
          element={<LedgerRouter />}
        />
        <Route
          path="ledger/:id"
          element={<LedgerDetailRouter />}
        />
        {/* Landlord ledger sub-views: transaction case file + tenant/property
            statements. Two-segment paths, so they never collide with the
            admin-only single-segment ledger/:id case file above. */}
        <Route
          path="ledger/tx/:entryId"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LedgerTransaction /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="ledger/statement/:contractId"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LedgerStatement /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="ledger/property/:propertyId"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LedgerPropertyStatement /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="profile"
          element={<Lazy><ProfilePage /></Lazy>}
        />
        <Route
          path="settings"
          element={<Lazy><SettingsPage /></Lazy>}
        />
        <Route
          path="documents"
          element={<Lazy><DocumentsPage /></Lazy>}
        />
        {/* Messages — self-contained two-pane page (no child routes) */}
        <Route
          path="messages"
          element={<Lazy><MessagesPage /></Lazy>}
        />

        {/* Tenant-only */}
        <Route
          path="browse"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><BrowseListings /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="saved"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><SavedListings /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="applications"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><ApplicationsPage /></Lazy>
            </RequireRole>
          }
        />
        {/* Guided form MUST be declared before the :id detail so "apply" is not
            captured as an application id. */}
        <Route
          path="applications/:id/apply"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><ApplicationForm /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="applications/:id"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><ApplicationDetail /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="payments"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><PaymentsPage /></Lazy>
            </RequireRole>
          }
        />
        {/* Tenant-only create page — declared BEFORE the overview so it reads as
            a sibling, and gated tighter (landlords triage, they don't raise). */}
        <Route
          path="maintenance/new"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><NewMaintenanceRequestPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="maintenance"
          element={
            <RequireRole roles={['tenant', 'landlord', 'admin']}>
              <MaintenanceRouter />
            </RequireRole>
          }
        />
        <Route
          path="maintenance/:id"
          element={
            <RequireRole roles={['tenant', 'landlord']}>
              <MaintenanceDetailRouter />
            </RequireRole>
          }
        />
        <Route
          path="compare"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><ComparePage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="verification"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><VerificationCenter /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="reviews"
          element={
            <RequireRole roles={['tenant']}>
              <Lazy><MyReviews /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord-only */}
        <Route
          path="properties"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><Properties /></Lazy>
            </RequireRole>
          }
        />
        {/* Declared BEFORE properties/:id so "new" isn't captured by the :id param. */}
        <Route
          path="properties/new"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><AddPropertyPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="properties/:id"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><PropertyDetail /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="properties/:propertyId/units/:unitId/listings/new"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><CreateListingForUnit /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="listings"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordListings /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="listings/create"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><CreateListing /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="listings/:id"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordListingDetail /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="applicants"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><Applicants /></Lazy>
            </RequireRole>
          }
        />
        {/* Static path MUST come before the :applicationId wildcard. */}
        <Route
          path="applicants/compare"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><ApplicantsCompare /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="applicants/:applicationId"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><ApplicantDetail /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="tenants"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><TenantManagement /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="tenants/:contractId"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><TenantDetail /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord analytics — real, scoped to the landlord's portfolio */}
        <Route
          path="analytics"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordAnalytics /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord verification — same lifecycle as tenant verification */}
        <Route
          path="landlord-verification"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordVerification /></Lazy>
            </RequireRole>
          }
        />

        {/* Landlord reviews — approved reviews on their properties + respond */}
        <Route
          path="landlord-reviews"
          element={
            <RequireRole roles={['landlord']}>
              <Lazy><LandlordReviews /></Lazy>
            </RequireRole>
          }
        />

        {/* Admin-only */}
        <Route
          path="listing-review"
          element={
            <RequireAdminCapability capability="moderate_listings">
              <Lazy><ListingReview /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="listing-review/:listingId"
          element={
            <RequireAdminCapability capability="moderate_listings">
              <Lazy><ListingReviewDetail /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="listing-review/:listingId/preview"
          element={
            <RequireAdminCapability capability="moderate_listings">
              <Lazy><ListingPreview /></Lazy>
            </RequireAdminCapability>
          }
        />
        {/* Legacy path → new Listing Review URL. */}
        <Route path="moderation" element={<Navigate to="/app/listing-review" replace />} />
        <Route
          path="audit"
          element={
            <RequireAdminCapability capability="view_audit">
              <Lazy><AuditLogs /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="audit/:id"
          element={
            <RequireAdminCapability capability="view_audit">
              <Lazy><AuditLogDetail /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="platform-analytics"
          element={
            <RequireAdminCapability capability="view_analytics">
              <Lazy><PlatformAnalytics /></Lazy>
            </RequireAdminCapability>
          }
        />
        {/* Scoped "your own workload" analytics — reachable by any admin;
            the response itself omits modules the admin lacks capabilities for. */}
        <Route
          path="admin-analytics"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><AdminAnalytics /></Lazy>
            </RequireRole>
          }
        />
        {/* Admin-exclusive but not capability-gated for reading — viewing the
            roster is a baseline admin privilege; only the moderation actions
            inside the page require manage_users. */}
        <Route
          path="users"
          element={
            <RequireRole roles={['admin']}>
              <Lazy><UsersPage /></Lazy>
            </RequireRole>
          }
        />
        <Route
          path="manage-access"
          element={
            <RequireAdminCapability capability="manage_access">
              <Lazy><ManageAccessPage /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="verifications"
          element={
            <RequireAdminCapability capability="review_verifications">
              <Lazy><VerificationsPage /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="verifications/:id"
          element={
            <RequireAdminCapability capability="review_verifications">
              <Lazy><VerificationDetailPage /></Lazy>
            </RequireAdminCapability>
          }
        />
        <Route
          path="review-moderation"
          element={
            <RequireAdminCapability capability="moderate_reviews">
              <Lazy><ReviewModeration /></Lazy>
            </RequireAdminCapability>
          }
        />
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  );
}
