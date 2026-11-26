# GradeView System Diagrams

These diagrams describe the current PHP application and its MySQL-backed workflows. The Mermaid blocks render as diagrams in GitHub and in Markdown previews that support Mermaid.

## Activity diagram

This diagram shows the shared sign-in flow followed by the available actions for each role.

```mermaid
flowchart TD
    START((Start)) --> LOGIN[Open GradeView sign-in]
    LOGIN --> CREDENTIALS[Submit username and password]
    CREDENTIALS --> LOOKUP[Look up an active user]
    LOOKUP --> VALID{User found and password valid?}
    VALID -- No --> ERROR[Show sign-in error]
    ERROR --> LOGIN
    VALID -- Yes --> SESSION[Create secure session and log sign-in]
    SESSION --> ROLE{User role}

    ROLE -- Admin --> ADMIN[Manage users, subjects, settings, and broadcasts]
    ROLE -- Teacher --> TEACHER[Manage subjects and enrollment; enter or update grades; send notices]
    ROLE -- Student --> STUDENT[Review personal grades and notifications; print a grade slip]

    ADMIN --> LOGOUT[Submit logout request with CSRF token]
    TEACHER --> LOGOUT
    STUDENT --> LOGOUT
    LOGOUT --> DESTROY[Invalidate session and return to sign-in]
    DESTROY --> END((End))
```

## Sequence diagram

This sequence follows a teacher saving grades for an existing subject roster, then a student viewing those grades and optionally printing a grade slip. The saved overall grade uses the current server-side weighting: prelim 20%, midterm 20%, prefinal 20%, and final 40%.

```mermaid
sequenceDiagram
    autonumber
    actor Teacher
    participant TB as Teacher browser
    participant App as GradeView PHP application
    participant DB as MySQL database
    actor Student
    participant SB as Student browser

    Note over Teacher,App: Teacher is signed in with the teacher role
    Teacher->>TB: Open grade ledger and select subject/period
    TB->>App: Request teacher.php
    App->>App: Validate teacher role and session
    App->>DB: Load subject roster and existing grade rows for this teacher
    DB-->>App: Roster and grade data
    App-->>TB: Render grade ledger

    Teacher->>TB: Enter scores and save ledger
    TB->>App: POST save_ledger with CSRF token
    App->>App: Validate CSRF token and allowed grading period
    App->>DB: BEGIN transaction
    loop For each student in the ledger
        App->>DB: Update period components and total for teacher's grade row
        App->>DB: Recalculate overall grade using 20/20/20/40 weighting
    end
    alt All updates succeed
        App->>DB: COMMIT transaction
        DB-->>App: Save completed
        App-->>TB: Redirect to ledger with success message
    else An update fails
        App->>DB: ROLLBACK transaction
        DB-->>App: Save failed
        App-->>TB: Redirect to ledger with error message
    end

    Note over Student,App: Student is signed in with the student role
    Student->>SB: Open grades dashboard
    SB->>App: Request students.php
    App->>App: Validate student role and session
    App->>DB: Fetch grades scoped to the signed-in student's ID
    DB-->>App: Grades, subjects, and teacher details
    App-->>SB: Render personal grades and notifications
    opt Student prints a grade slip
        Student->>SB: Choose Print Grade Slip
        SB->>App: Request print_grade.php
        App->>App: Restrict student report to signed-in student's ID
        App->>DB: Fetch the student's grade records
        DB-->>App: Grade records
        App-->>SB: Render printable academic report
        Student->>SB: Print report
    end
```
