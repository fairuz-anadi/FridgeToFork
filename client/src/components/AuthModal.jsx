import { useEffect, useId, useState } from "react";

const initialLogin = { email: "", password: "" };
const initialSignup = {
  name: "",
  username: "",
  email: "",
  password: "",
  password_confirmation: "",
};

const GOOGLE_CLIENT_ID = import.meta.env.VITE_GOOGLE_CLIENT_ID;
const GSI_SCRIPT_ID = "google-identity-script";

/**
 * Google's own sign-in button (Google Identity Services). Google hands back a
 * signed ID token, which the API verifies before logging the cook in.
 */
function GoogleButton({ mode, onGoogleLogin, setError }) {
  const containerId = useId().replace(/:/g, "");

  useEffect(() => {
    if (!GOOGLE_CLIENT_ID) return undefined;
    let cancelled = false;

    function renderButton() {
      const container = document.getElementById(containerId);
      if (cancelled || !container || !window.google?.accounts?.id) return;

      window.google.accounts.id.initialize({
        client_id: GOOGLE_CLIENT_ID,
        callback: async (response) => {
          setError("");
          try {
            await onGoogleLogin(response.credential);
          } catch (err) {
            setError(err.message || "Google sign-in failed.");
          }
        },
      });
      container.innerHTML = "";
      window.google.accounts.id.renderButton(container, {
        theme: "outline",
        size: "large",
        shape: "pill",
        text: mode === "signup" ? "signup_with" : "continue_with",
        width: Math.min(360, container.clientWidth || 320),
      });
    }

    if (window.google?.accounts?.id) {
      renderButton();
    } else {
      let script = document.getElementById(GSI_SCRIPT_ID);
      if (!script) {
        script = document.createElement("script");
        script.id = GSI_SCRIPT_ID;
        script.src = "https://accounts.google.com/gsi/client";
        script.async = true;
        script.onerror = () => setError("Google sign-in could not be loaded. Check your connection and try again.");
        document.body.appendChild(script);
      }
      script.addEventListener("load", renderButton, { once: true });
    }

    return () => {
      cancelled = true;
    };
  }, [containerId, mode, onGoogleLogin, setError]);

  if (!GOOGLE_CLIENT_ID) {
    return <p className="muted auth-note">Google sign-in is not set up on this site.</p>;
  }

  return <div id={containerId} className="google-button-slot" />;
}

export default function AuthModal({
  mode,
  onClose,
  onSwitchMode,
  onLogin,
  onRegister,
  onGoogleLogin,
}) {
  const [loginForm, setLoginForm] = useState(initialLogin);
  const [signupForm, setSignupForm] = useState(initialSignup);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    setError("");
  }, [mode]);

  useEffect(() => {
    const onKeyDown = (event) => {
      if (event.key === "Escape") onClose();
    };

    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  async function handleSubmit(event) {
    event.preventDefault();
    setError("");

    let payloadToSend;

    if (mode === "signup") {
      const rawUser = signupForm.username.trim() || signupForm.name.trim() || "Anadi";
      const cleanUsername = rawUser.replace(/[^a-zA-Z0-9_-]/g, "_") || "anadi";
      const name = signupForm.name.trim() || rawUser;
      const email = signupForm.email.trim() || `${cleanUsername.toLowerCase()}@fridgetofork.test`;
      const password = signupForm.password || "Password123!";
      const password_confirmation = signupForm.password_confirmation || password;

      if (password.length < 6) {
        setError("Password must be at least 6 characters long.");
        return;
      }

      if (signupForm.password && signupForm.password_confirmation && signupForm.password !== signupForm.password_confirmation) {
        setError("Passwords do not match. Please check and try again.");
        return;
      }

      payloadToSend = {
        name,
        username: cleanUsername,
        email,
        password,
        password_confirmation,
      };
    } else {
      if (!loginForm.email.trim()) {
        setError("Please enter your email or username.");
        return;
      }
      if (!loginForm.password) {
        setError("Please enter your password.");
        return;
      }
      payloadToSend = loginForm;
    }

    setSubmitting(true);

    try {
      if (mode === "login") {
        await onLogin(payloadToSend);
      } else {
        await onRegister(payloadToSend);
      }
    } catch (submitError) {
      setError(submitError.message || "Something went wrong. Please try again.");
    } finally {
      setSubmitting(false);
    }
  }

  const handleInputChange = (setter, field, value) => {
    setError("");
    setter((current) => ({ ...current, [field]: value }));
  };

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className={`modal-panel auth-modal auth-modal--${mode}`} onClick={(event) => event.stopPropagation()}>
        <button className="modal-close" onClick={onClose} type="button" aria-label="Close">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
        </button>

        <div className="auth-modal-header">
          <p className="eyebrow">{mode === "login" ? "Welcome back" : "Join the community"}</p>
          <h2>{mode === "login" ? "Sign in to FridgeToFork" : "Create your account"}</h2>
          <p className="section-copy">
            Share recipes, earn points, and help other cooks with ratings and reviews.
          </p>
        </div>

        <form className="stack-form" onSubmit={handleSubmit}>
          {mode === "signup" ? (
            <>
              <div className="auth-form-row">
                <input
                  placeholder="Full name"
                  value={signupForm.name}
                  onChange={(event) => handleInputChange(setSignupForm, "name", event.target.value)}
                  required
                />
                <input
                  placeholder="Username"
                  value={signupForm.username}
                  onChange={(event) => handleInputChange(setSignupForm, "username", event.target.value)}
                  required
                />
              </div>

              <input
                type="email"
                placeholder="Email"
                value={signupForm.email}
                onChange={(event) => handleInputChange(setSignupForm, "email", event.target.value)}
                required
              />

              <div className="auth-form-row">
                <input
                  type="password"
                  placeholder="Password"
                  value={signupForm.password}
                  onChange={(event) => handleInputChange(setSignupForm, "password", event.target.value)}
                  required
                />
                <input
                  type="password"
                  placeholder="Confirm password"
                  value={signupForm.password_confirmation}
                  onChange={(event) => handleInputChange(setSignupForm, "password_confirmation", event.target.value)}
                  required
                />
              </div>
            </>
          ) : (
            <>
              <input
                type="text"
                placeholder="Email or Username"
                value={loginForm.email}
                onChange={(event) => handleInputChange(setLoginForm, "email", event.target.value)}
                required
              />
              <input
                type="password"
                placeholder="Password"
                value={loginForm.password}
                onChange={(event) => handleInputChange(setLoginForm, "password", event.target.value)}
                required
              />
            </>
          )}

          {error && <p className="form-error">{error}</p>}

          <button className="button" disabled={submitting} type="submit">
            {submitting ? "Please wait..." : mode === "login" ? "Log In" : "Create Account"}
          </button>
        </form>

        <div className="auth-divider"><span>or continue with Google</span></div>
        <GoogleButton mode={mode} onGoogleLogin={onGoogleLogin} setError={setError} />
        <p className="auth-switch">
          {mode === "login" ? "Need an account?" : "Already a member?"}{" "}
          <button
            className="link-button"
            onClick={() => {
              setError("");
              onSwitchMode(mode === "login" ? "signup" : "login");
            }}
            type="button"
          >
            {mode === "login" ? "Sign up" : "Log in"}
          </button>
        </p>
      </div>
    </div>
  );
}
