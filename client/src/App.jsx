import { useEffect, useMemo, useState } from "react";
import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import "./App.css";
import { api, setToken } from "./api/api";
import AuthModal from "./components/AuthModal";
import ChatWidget from "./components/ChatWidget";
import Footer from "./components/Footer";
import SiteAbout from "./components/SiteAbout";
import SiteHome from "./components/SiteHome";
import TopNav from "./components/TopNav";
import { ToastProvider } from "./components/ToastProvider";
import { useToast } from "./components/useToast";
import UserHub from "./components/UserHub";
import AdminDashboard from "./pages/AdminDashboard";
import ContactPage from "./pages/ContactPage";
import CookMode from "./pages/CookMode";
import CuisineMap from "./pages/CuisineMap";
import MealPlanner from "./pages/MealPlanner";
import PantryPage from "./pages/PantryPage";
import ProfilePreferences from "./pages/ProfilePreferences";
import RecipeEditor from "./pages/RecipeEditor";
import RecipeLibrary from "./pages/RecipeLibrary";
import ShoppingList from "./pages/ShoppingList";
import UserProfile from "./pages/UserProfile";

function ProtectedRoute({ user, children }) {
  return user ? children : <Navigate to="/" replace />;
}

function AdminRoute({ user, children }) {
  return user?.is_admin ? children : <Navigate to="/" replace />;
}

function App() {
  return (
    <ToastProvider>
      <AppContent />
    </ToastProvider>
  );
}

function AppContent() {
  const [user, setUser] = useState(null);
  const [authMode, setAuthMode] = useState(null);
  const [authLoading, setAuthLoading] = useState(true);
  const { showToast } = useToast();

  useEffect(() => {
    async function boot() {
      try {
        const response = await api.me();
        setUser(response.user);
      } catch {
        setToken(null);
      } finally {
        setAuthLoading(false);
      }
    }

    boot();
  }, []);

  const authActions = useMemo(
    () => ({
      async login(payload) {
        const response = await api.login(payload);
        setToken(response.token);
        setUser(response.user);
        setAuthMode(null);
        showToast("Log-in successful.");
      },
      async register(payload) {
        const response = await api.register(payload);
        setToken(response.token);
        setUser(response.user);
        setAuthMode(null);
        showToast("Sign-up successful.");
      },
      async googleLogin(idToken) {
        const response = await api.googleLogin(idToken);
        setToken(response.token);
        setUser(response.user);
        setAuthMode(null);
        showToast("Log-in successful.");
      },
      async logout() {
        try {
          await api.logout();
        } catch {
          // Ignore logout API failures and clear local state anyway.
        } finally {
          setToken(null);
          setUser(null);
          showToast("You have been logged out.");
        }
      },
    }),
    [showToast]
  );

  if (authLoading) {
    return <div className="shell-loader">Loading FridgeToFork…</div>;
  }

  return (
    <BrowserRouter>
      <div className="app-shell">
        <TopNav user={user} onOpenAuth={setAuthMode} onLogout={authActions.logout} />

        <main className="app-main">
          <Routes>
            <Route path="/" element={<SiteHome user={user} onOpenAuth={setAuthMode} />} />
            <Route path="/about" element={<SiteAbout />} />
            <Route
              path="/fridge"
              element={<PantryPage user={user} onRequireAuth={() => setAuthMode("login")} />}
            />
            <Route path="/cuisines" element={<CuisineMap />} />
            <Route path="/recipes/:recipeId/cook" element={<CookMode />} />
            <Route
              path="/meal-plan"
              element={
                <ProtectedRoute user={user}>
                  <MealPlanner user={user} />
                </ProtectedRoute>
              }
            />
            <Route
              path="/shopping-list"
              element={
                <ProtectedRoute user={user}>
                  <ShoppingList user={user} />
                </ProtectedRoute>
              }
            />
            <Route
              path="/preferences"
              element={
                <ProtectedRoute user={user}>
                  <ProfilePreferences user={user} onUserChange={setUser} />
                </ProtectedRoute>
              }
            />
            <Route path="/contact" element={<ContactPage user={user} onRequireAuth={() => setAuthMode("login")} />} />
            <Route
              path="/recipes"
              element={<RecipeLibrary user={user} onRequireAuth={() => setAuthMode("login")} />}
            />
            <Route
              path="/recipes/new"
              element={
                <ProtectedRoute user={user}>
                  <RecipeEditor user={user} />
                </ProtectedRoute>
              }
            />
            <Route
              path="/recipes/:recipeId/edit"
              element={
                <ProtectedRoute user={user}>
                  <RecipeEditor user={user} />
                </ProtectedRoute>
              }
            />
            <Route
              path="/profile"
              element={
                <ProtectedRoute user={user}>
                  <UserHub />
                </ProtectedRoute>
              }
            />
            <Route
              path="/users/:userId"
              element={<UserProfile user={user} />}
            />
            <Route
              path="/admin"
              element={
                <AdminRoute user={user}>
                  <AdminDashboard />
                </AdminRoute>
              }
            />
          </Routes>
        </main>

        <Footer />

        <ChatWidget user={user} />

        {authMode && (
          <AuthModal
            mode={authMode}
            onClose={() => setAuthMode(null)}
            onSwitchMode={setAuthMode}
            onLogin={authActions.login}
            onRegister={authActions.register}
            onGoogleLogin={authActions.googleLogin}
          />
        )}
      </div>
    </BrowserRouter>
  );
}

export default App;
