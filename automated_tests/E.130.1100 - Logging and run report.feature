Feature: E.130.1100 - The system shall log every change applied by Project XML Sync and keep a downloadable report and design backup for each run.

  As a REDCap administrator
  I want an audit trail of what was copied, by whom and when
  So that amendments to a trial are traceable

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

  Scenario: E.130.1100 - Applied changes are logged
    Given I create a new project named "E.130.1100" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/E130_base.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"

    When I click on the link labeled "Project XML Sync"
    And I upload a "xml" format file located at "fixtures/cdisc_files/E130_amended.xml", by clicking the button near "Select the Project XML file" to browse for the file, and clicking the button labeled "Upload and compare" to upload the file
    And I click on the button labeled "Apply selected changes"
    Then I should see "change(s) applied"
    And I should see a link labeled "Download the run report (CSV)"

    # One project Logging entry per applied item
    When I click on the link labeled "Logging"
    Then I should see "Project XML Sync: add data quality rule 'AE grade missing'"
    And I should see "Project XML Sync: add event 'event_4_arm_1'"
    And I should see "Project XML Sync: add field 'ae_term'"

    # Run history on the module page
    When I click on the link labeled "Project XML Sync"
    Then I should see "Previous runs (1)"

    # Module log
    When I click on the link labeled "Manage"
    And I click on the link labeled "View Logs"
    Then I should see "External Module Logs"
    And I should see a table header and row containing the following values in a table:
      | Module           | Message      | UserName   |
      | project_xml_sync | Sync applied | Test_Admin |
      | project_xml_sync | XML uploaded | Test_Admin |

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
