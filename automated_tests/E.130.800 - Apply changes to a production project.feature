Feature: E.130.800 - The system shall support the ability to apply the selected differences to a production project, putting field and instrument changes into Draft Mode and applying all other components directly.

  As a REDCap end user
  I want the amendments made on the TEST server copied to the production project
  So that REDCap's draft review still protects field changes and nothing is missed

  Scenario: Enable external module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Project XML Sync - v1.0.0"

  Scenario: E.130.800 - First pass: fields to Draft Mode, other components applied
    Given I create a new project named "E.130.800" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/E130_base.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"

    # Move the project to production
    When I click on the link labeled "Project Setup"
    And I click on the button labeled "Move project to production"
    And I click on the radio labeled "Keep ALL data saved so far" in the dialog box
    And I click on the button labeled "YES, Move to Production Status" in the dialog box
    Then I should see "Project status: Production"

    When I click on the link labeled "Project XML Sync"
    And I upload a "xml" format file located at "fixtures/cdisc_files/E130_amended.xml", by clicking the button near "Select the Project XML file" to browse for the file, and clicking the button labeled "Upload and compare" to upload the file
    Then I should see "Differences"
    # Mapping, repeating setup and survey of the new instrument wait for the draft to be approved
    And I should see "Available after the drafted instrument is approved"

    When I click on the button labeled "Apply selected changes"
    Then I should see "Results"
    And I should see "change(s) applied"
    And I should see "Field changes are in Draft Mode"
    And I should see "Some items depend on new instruments"

    # Fields are drafted, not live
    When I click on the link labeled "Designer"
    Then I should see "Draft Mode"
    And I should see "Adverse Events"

    # Other components are live immediately
    When I click on the link labeled "Data Quality"
    Then I should see "AE grade missing"
    And I should see "[identifier] > 10"
    When I click on the link labeled "Alerts & Notifications"
    Then I should see "New AE alert"
    When I click on the link labeled "Project Setup"
    And I click on the button labeled "Define My Events"
    Then I should see "Event 4"

  Scenario: E.130.800 - Second pass after the draft is approved
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.130.800"
    And I click on the link labeled "Designer"
    And I click on the button labeled "Submit Changes for Review"
    And I click on the button labeled "Submit" in the dialog box
    Then I should see "Changes Were Made Automatically"

    When I click on the link labeled "Project XML Sync"
    Then I should see "Comparing with E130_amended.xml"
    And I should NOT see "Available after the drafted instrument is approved"
    And I should see "event_1_arm_1 -> adverse_events"
    When I click on the button labeled "Apply selected changes"
    Then I should see "change(s) applied"

    # Only the unticked (opt-in) removals are left
    When I click on the link labeled "Project XML Sync"
    Then I should NOT see "ae_term"
    And I should NOT see "event_1_arm_1 -> adverse_events"
    And I should NOT see "AE grade missing"
    And I should see "text2"

    # Disable external module in Control Center
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Project XML Sync - v1.0.0"
    And I logout

    # Verify no exceptions are thrown in the system
    Given I open Email
    Then I should NOT see an email with subject "REDCap External Module Hook Exception - project_xml_sync"
