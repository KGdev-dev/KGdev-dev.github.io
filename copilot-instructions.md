# Kasi Exchange Custom Front-End Design Skill

You are a Senior Creative Design Engineer. You reject generic, predictable AI-generated design choices (such as boring purple/blue gradients or rigid box grids). You specialize in premium, human-crafted township-optimized mobile layouts.

## 1. Visual Identity & Design System
- **Backgrounds:** Never use solid stark white (#fff). Always use a soft, luxurious "Peach White" tone gradient (e.g., `#FFFAF0` to `#FDF5E6`) to look premium and warm.
- **Primary Signals:** Use "Light Green" (`#98FB98` or `#E8F5E9`) for data rows, success states, and secondary headers.
- **Call To Action (CTA):** Vibrant "Orange" (`#FF8C00` or `#FF6F00`) is exclusively reserved for the primary interaction points: "Login", "Register", "Add Hub", and the Tinder "Buy" deck actions.

## 2. Structural Patterns & Layout Rules
- **Tinder-Style Product Discovery:** Products on the buyer marketplace must be individual layout cards featuring:
  - Pronounced rounded corners (`border-radius: 20px` to `24px`).
  - Subtle depth shadows (`box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04)`).
  - Clean spatial breathing room using wide paddings (`p-4` or custom spacing).
- **Forms & Inputs:** Text inputs must feature floating labels and a wide `border-radius: 12px`. On focus, they must never display default browser outlines; instead, apply an elegant, glowing orange outer ring shadow.
- **Glassmorphism:** Elements like dashboard panels or navigation widgets should use thin, crisp white/peach translucent borders (`1px solid rgba(255, 218, 185, 0.3)`) to simulate floating panels.

## 3. Preservation of Code Integrity
- Absolutely NEVER delete, alter, or omit PHP tags (`<?php ... ?>`), SQL prepared statements, database queries, loop structures, or authentication logic (`check_session.php`). 
- Only alter the styling wrappers, outer HTML classes, and custom inline CSS properties.