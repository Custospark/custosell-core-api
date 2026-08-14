<?php

namespace Database\Seeders;

use App\Models\GuideFaq;
use Illuminate\Database\Seeder;

class GuideFaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            // ── Getting Started ─────────────────────────────────
            [
                'question' => 'What is Custosell?',
                'answer' => 'Custosell is an all-in-one business operating system for businesses of all sizes - from solopreneurs managing personal projects to large retail chains running multi-department operations. It combines point of sale, inventory management, customer relationships, expenses, invoicing, payroll, accounting, and an online storefront into a single app that works with or without internet.',
                'sort_order' => 1,
            ],
            [
                'question' => 'What is the difference between a Personal, Business, and Shopping account?',
                'answer' => "A Shopping account is free and designed for storefront buyers. It gives you access to Discover and My Orders only - browse products & services, follow businesses, save items to your wishlist, and place and track orders. There is no dashboard, no business setup, and no subscription or fees.\n\nA Personal account is designed for freelancers, solopreneurs, and individuals who need tools like project management, expense tracking, document storage, and accounting without a full retail POS. You pay a flat monthly fee and buy only the modules you need.\n\nA Business account is built for registered businesses with physical or online stores. It includes point of sale, inventory management, customer management, staff roles, shift management, and a public storefront. Business plans are tiered (Essential, Professional, Enterprise) with increasing features.",
                'sort_order' => 2,
            ],
            [
                'question' => 'How do I get started with Custosell?',
                'answer' => "Create a free account and choose your account type:\n\n- Shopping - free for storefront buyers. Start browsing and ordering from every business on Discover immediately. No plan, no setup, no fees.\n\n- Personal - pick a Personal plan, pay the monthly fee, and start using your selected modules immediately. A free trial is included.\n\n- Business - pick a Business plan (Essential, Professional, or Enterprise), pay the one-time setup fee, and you get a 30-day trial to test everything. No credit card required to start.\n\nFor Personal and Business accounts, you can upgrade, downgrade, or cancel anytime. You can also upgrade from a Shopping account later if you decide to start selling.",
                'sort_order' => 3,
            ],
            [
                'question' => 'What platforms does Custosell run on?',
                'answer' => 'Custosell runs on Windows laptops and tablets. Support for Mac and Linux is coming soon. The public storefront (Discover) works on any phone browser, so your customers can browse and place orders from their phones.',
                'sort_order' => 4,
            ],

            // ── Plans & Billing ───────────────────────────────────
            [
                'question' => 'How much does Custosell cost?',
                'answer' => "Pricing depends on your account type and chosen plan:\n\n- Personal - a flat monthly fee that includes access to the modules you subscribe to. No long-term contract.\n\n- Business - tiered plans (Essential, Professional, Enterprise). Each includes a one-time setup fee followed by a monthly or yearly subscription. See our Plans page for current pricing in your currency region (USD or UGX).",
                'sort_order' => 5,
            ],
            [
                'question' => 'Is there a free trial?',
                'answer' => 'Yes. Both Personal and Business accounts come with a free trial period after setup. For Business accounts, you get a full 30-day trial to test every feature before you pay. No credit card is required to start your trial.',
                'sort_order' => 6,
            ],
            [
                'question' => 'What happens when my trial ends?',
                'answer' => 'When your trial ends, your subscription status changes to past due. You have a 7-day grace period to make a payment and continue using Custosell without interruption. If no payment is made during the grace period, your subscription is suspended and access to your tools is temporarily blocked until you reactivate. Your data is never deleted.',
                'sort_order' => 7,
            ],
            [
                'question' => 'Can I switch plans or cancel anytime?',
                'answer' => 'Yes. You can upgrade or downgrade your plan at any time. Upgrades take effect immediately. Downgrades take effect at the end of your current billing period. There are no lock-in contracts - cancel anytime and your access continues until the end of your billing period.',
                'sort_order' => 8,
            ],

            // ── For Personal Accounts ────────────────────────────
            [
                'question' => 'What tools are available on a Personal account?',
                'answer' => "Personal accounts can choose from the following modules:\n\n- Sales CRM / Pipeline - Manage leads, deals, and customer interactions with a visual pipeline board. Track every stage from first contact to closed deal.\n- Projects & Estimates - Create professional estimates and invoices, manage projects with tasks and milestones, and collaborate with your team in real time.\n- Expenses - Track and categorize your business expenses. Record receipts, attach images, and generate expense reports for tax time.\n- Accounting - Full double-entry accounting with a chart of accounts, journal entries, trial balance, income statement, and balance sheet.\n- Documents - Store and organize your business files securely in the cloud. Upload contracts, receipts, reports, and any other documents you need to keep.\n\nEach module is available on a monthly subscription basis. You only pay for what you use.",
                'sort_order' => 9,
            ],
            [
                'question' => 'Can I upgrade from Personal to Business?',
                'answer' => 'Yes. You can upgrade from a Personal account to a full Business account at any time. Your profile transitions to a business account with access to POS, inventory management, staff roles, and a public storefront. Your existing data and subscription history are preserved.',
                'sort_order' => 10,
            ],
            [
                'question' => 'How does module purchasing work for Personal accounts?',
                'answer' => 'On a Personal plan, you select which modules you want to use - Sales CRM / Pipeline, Projects & Estimates, Expenses, Accounting, or Documents. Each module adds to your monthly fee. You can enable or disable modules from your account settings anytime; changes take effect on your next billing cycle.',
                'sort_order' => 11,
            ],

            // ── For Business Accounts ────────────────────────────
            [
                'question' => 'What modules are included in each Business plan?',
                'answer' => "Essential includes point of sale, inventory, customers, expenses, dashboard, and a public online storefront.\n\nProfessional adds pipeline (Sales CRM), estimates & projects, documents, and marketplace access.\n\nEnterprise adds full accounting, HR & payroll, and forecasting.\n\nAll plans include staff management with granular role-based permissions.",
                'sort_order' => 12,
            ],
            [
                'question' => 'Can I control what my staff see and do?',
                'answer' => 'Yes. You have full control over staff permissions. Create custom roles and assign specific module access to each staff member. A cashier sees only the point of sale. Your inventory manager sees only stock-related sections. The business owner has unrestricted access to all modules.',
                'sort_order' => 13,
            ],

            // ── For Shopping Accounts ────────────────────────────
            [
                'question' => 'What is a Shopping account?',
                'answer' => 'A Shopping account is a free account for storefront buyers. It gives you access to Discover and My Orders only - browse products & services from every business on Custosell, follow businesses you like, save items to your wishlist, and place and track orders. Shopping accounts have no dashboard, no business setup, and no subscription or fees.',
                'sort_order' => 14,
            ],
            [
                'question' => 'Do Shopping accounts cost anything?',
                'answer' => 'No. Shopping accounts are completely free. You never subscribe to a plan and there is nothing to pay - you only pay a business directly for the products or services you order from them.',
                'sort_order' => 15,
            ],
            [
                'question' => 'Can I upgrade from a Shopping account to a Personal or Business account?',
                'answer' => 'Yes. If you later decide to manage projects on your own or sell your own products, you can upgrade from your Shopping account to a Personal or Business account at any time. Your existing order history and wishlist are preserved.',
                'sort_order' => 16,
            ],

            // ── Technical & Data ─────────────────────────────────
            [
                'question' => 'Does Custosell work without internet?',
                'answer' => 'Yes. Custosell is built offline-first. You can ring up sales, add customers, record expenses, and manage inventory without any internet connection. Everything syncs automatically to the cloud when you reconnect. This is ideal for markets, remote locations, or any situation with unreliable internet.',
                'sort_order' => 14,
            ],
            [
                'question' => 'Is my data safe with Custosell?',
                'answer' => 'Absolutely. All data is encrypted at rest (AES-256) and in transit (TLS 1.3). Your data belongs to you - we never share or sell it to third parties. Local backups ensure you never lose data even if your device is lost or damaged. Install Custosell on a new device, log in, and your data restores from the cloud.',
                'sort_order' => 15,
            ],
        // ── Features & Tools ──────────────────────────────
            [
                'question' => 'Does Custosell include Point of Sale (POS) and how does it work offline?',
                'answer' => "Yes. Custosell is powerful Point of Sale software for retail, restaurants, and services. Cashiers ring up sales, apply discounts, choose payment methods, and print or share receipts in seconds - on a computer, tablet, or phone. Because it's offline-first, sales keep flowing even when the internet drops. Open a shift, record every transaction, then close with a clear cash-at-handover report.",
                'sort_order' => 17,
            ],
            [
                'question' => 'Is Custosell accounting and bookkeeping software?',
                'answer' => "Yes. Custosell bundles Accounting software with your daily Point of Sale, so your books are built from the sales you actually make. Log expenses, track payables and receivables, and see profit-and-loss numbers without copying data between apps. Every sale and expense feeds the same ledger, ready for tax season.",
                'sort_order' => 19,
            ],
            [
                'question' => 'Does Custosell include inventory and supply chain management?',
                'answer' => "Yes. Custosell keeps a live count of every product you hold. Sales from your POS and storefront reduce stock automatically, and you can raise purchase orders to suppliers, track fulfillment, and record receipt. You always know what to reorder, what is moving, and what is sitting on the shelf - across branches and registers.",
                'sort_order' => 20,
            ],
            [
                'question' => 'Does Custosell include HR and payroll software?',
                'answer' => "Yes. Custosell includes HR and payroll tools for growing teams: attendance, leave, and payroll in one place. Control who has access to what with role-based permissions, and keep team records organized - all from the same platform you use to run sales and inventory.",
                'sort_order' => 21,
            ],
            [
                'question' => 'Does Custosell provide an e-commerce storefront?',
                'answer' => "Yes. Custosell turns your business into e-commerce with a public storefront. Share a shop link so customers browse your catalog, save wishlists, place order requests, and track status - while stock stays tied to your real inventory. There's no separate database to reconcile.",
                'sort_order' => 22,
            ],
            [
                'question' => 'Does Custosell include a sales pipeline and CRM?',
                'answer' => "Yes. Custosell includes a sales pipeline and CRM to manage leads, opportunities, and follow-ups on simple boards. Pipelines and notes are shared, so salespeople, managers, and owners collaborate in real time and see who owns each deal. Customer records hold contact and purchase history.",
                'sort_order' => 23,
            ],
            [
                'question' => 'Does Custosell include project management tools?',
                'answer' => "Yes. Custosell includes projects, estimates, and productivity tools - quotes that turn into projects, shared boards for tasks and deadlines, and team collaboration. Because projects sit beside invoicing and expenses, you can quote a job, track its costs, and send an invoice the moment it's done.",
                'sort_order' => 24,
            ],
            [
                'question' => 'Does Custosell track expenses?',
                'answer' => 'Yes. Custosell records spendings as they happen, with categories, suppliers, and monthly reports so you can see where cash actually goes. Expenses paid from a store shift are reconciled into the close report to keep the books balanced.',
                'sort_order' => 25,
            ],
            [
                'question' => 'Does Custosell include financial forecasting?',
                'answer' => "Yes. Financial forecasting in Custosell turns your recorded sales and expense history into a forward-looking cash outlook and what-if scenarios - so you can plan hires, stock, and growth with more confidence instead of guesswork.",
                'sort_order' => 26,
            ],
            [
                'question' => 'Does Custosell include document management?',
                'answer' => 'Yes. Document management in Custosell keeps contracts, invoices, quotations, and records organized for the whole team - searchable, and linked to the customers, jobs, and invoices that need them.',
                'sort_order' => 27,
            ],
        ];

        foreach ($faqs as $faq) {
            GuideFaq::updateOrCreate(
                ['question' => $faq['question']],
                $faq,
            );
        }

        $this->command?->info('Seeded ' . count($faqs) . ' FAQs.');
    }
}
