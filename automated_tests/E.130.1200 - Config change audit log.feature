Feature: E.130.1200 - The system shall record configuration changes for the Project XML Sync external module (who, when, old->new) to the module's View Logs page.

  As a REDCap administrator
  I want every configuration change to be written to the module's External Module Logs
  So that there is an audit trail of who changed which setting, when, and from what value to what.

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

  Scenario: E.130.1200 - Configuration changes are logged with old and new values
    Given I create a new project named "E.130.1200" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/E130_base.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"

    When I click on the button labeled "Configure"
    Then I should see "Configure Module"
    And I check the checkbox labeled "Only allow administrators to apply changes"
    Then I click on the button labeled "Save"
    And I should see "Project XML Sync - v1.0.0"

    When I click on the link labeled "View Logs"
    Then I should see "External Module Logs"
    And I should see a table header and row containing the following values in a table:
      | Module           | Message                         | UserName   |
      | project_xml_sync | Configuration changed (project) | Test_Admin |
    When I click on the first button labeled "Show Parameters"
    Then I should see "Log Entry Parameters"
    And I should see a table header and row containing the following values in a table:
      | Name      | Value                   |
      | setting   | restrict-to-super-users |
      | old_value | (empty)                 |
      | new_value | 1                       |
    And I click on the button labeled "Close"

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
