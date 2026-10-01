-- =====================================================================
-- OPTIONAL demo data: adds a sample "Project Manager" target job to an
-- EXISTING account. No users or passwords are created here.
--
-- 1. Register an account in the app first.
-- 2. Change the email below to that account's email.
-- 3. mysql -u USER -p interview_copilot < database/demo-data.sql
--
-- (You can also click "Add sample job" on the Jobs page instead.)
-- =====================================================================

SET @demo_email = 'you@example.com';

INSERT INTO jobs (user_id, title, company, industry, location, description, main_skills, interview_type, seniority)
SELECT u.id,
       'Project Manager',
       'Northwind Infrastructure Ltd',
       'Construction & Infrastructure',
       'London (Hybrid)',
       'We are looking for an experienced Project Manager to lead the delivery of mid-sized infrastructure and technology projects from initiation to handover.\n\nResponsibilities:\n- Plan, schedule and manage projects valued between £2m and £10m against scope, time, cost and quality targets.\n- Lead cross-functional teams of engineers, contractors and suppliers.\n- Manage stakeholders including clients, senior leadership and regulators; run steering-committee reporting.\n- Identify, track and mitigate risks and issues; maintain RAID logs.\n- Control budgets, forecasts and change requests.\n- Drive continuous improvement and lessons-learned reviews.\n\nRequirements:\n- 5+ years of project management experience.\n- PRINCE2, APM or PMP certification (or equivalent).\n- Strong stakeholder management, communication and negotiation skills.\n- Experience with MS Project, Jira or similar planning tools.\n- Proven ability to lead and motivate teams and resolve conflict.\n- Commercial awareness and experience managing contractors.',
       'Stakeholder management, risk management, budgeting, scheduling, team leadership, PRINCE2',
       'behavioural',
       'senior'
FROM users u
WHERE u.email = @demo_email;
