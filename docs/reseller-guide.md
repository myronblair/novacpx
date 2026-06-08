# NovaCPX — Reseller Guide

## Accessing the Reseller Panel

The reseller panel runs on port **8881**. Navigate to `https://<server-ip>:8881` and log in with your reseller credentials.

Your admin will provide your username and initial password.

## Overview

As a reseller you can create and manage hosting accounts for your customers. You only see accounts assigned to you — other resellers' accounts are hidden. The admin can see all accounts.

## Accounts

### Creating a customer account

**Accounts → Create Account**

| Field | Notes |
|-------|-------|
| Username | Lowercase, alphanumeric. Unique on the server. |
| Domain | Primary domain. DNS zone and web vhost created automatically. |
| Email | Customer's email. Receives welcome notification (if enabled). |
| Password | Min 8 characters. |
| Package | Resource limits. Available packages are defined by your admin. |
| PHP version | PHP-FPM version for this account. |

### Managing accounts

From **Accounts**, you can:

- **Suspend** — disables the customer's website
- **Unsuspend** — re-enables it
- **Change Password** — reset the customer's panel and system password

You cannot terminate accounts — contact your admin if an account needs to be removed.

## Domains

Under each account you can:

- Add **addon domains** (additional websites on the same account)
- Add **subdomains**
- Add **redirects**
- View the document root for each domain

## Email

Manage virtual email addresses for your customers' domains:

- Create mailboxes (username@domain.com)
- Set passwords
- Suspend / reactivate mailboxes
- Set storage quotas

Customers access their email via the webmail interface at port 8883, or by configuring an email client with the server's hostname.

### Webmail SSO

Customers can be redirected to webmail with a single-sign-on link from the user panel. The SSO token is valid for 5 minutes.

## DNS

View and edit DNS records for accounts you manage. Standard record types are supported (A, AAAA, CNAME, MX, TXT, etc.). Changes are applied live via `rndc reload`.

## Databases

Create and manage MySQL databases for your customers. Each database is prefixed with the account username to avoid conflicts.

## FTP

Create FTP accounts scoped to specific directories of an account. Useful for giving customers access to subdirectories without full SSH.

## Docker

If Docker is enabled on the server, you can:

- View containers running under your customers' accounts
- Set per-customer Docker quotas (max containers, RAM, CPU)
- Launch one-click app catalog deployments on behalf of a customer

## White Label

**White Label** lets you brand the reseller panel and user panel with your own identity.

| Setting | Notes |
|---------|-------|
| Panel Name | Replaces "NovaCPX" in the panel header |
| Logo | Upload a PNG/JPG/SVG (max 512 KB) |
| Favicon | Small icon shown in browser tabs |
| Primary Color | Main accent color (hex) |
| Accent Color | Secondary color (hex) |
| Support Email | Shown in customer-facing error messages |
| Support URL | Link in the panel footer |
| Hide "Powered by NovaCPX" | Suppress the footer attribution |
| Custom CSS | Inject additional CSS into all panel pages |

Changes take effect immediately for new page loads.

## Customers logging in

Your customers log in to the **user panel** at port **8880**. Give them:

- URL: `https://<server-ip>:8880`
- Their username and password (set when you created their account)

From the user panel they can manage their own files, email, databases, FTP accounts, DNS records, SSL certificates, and cron jobs.
